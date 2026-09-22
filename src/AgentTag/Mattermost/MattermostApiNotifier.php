<?php

namespace App\AgentTag\Mattermost;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function Symfony\Component\String\u;

final class MattermostApiNotifier implements MattermostNotifier
{
    private ?string $botUserId = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly MattermostApiSettings $settings,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    #[\Override]
    public function showTyping(MattermostInboundEvent $event): void
    {
        if (!$this->settings->enabled()) {
            return;
        }

        $payload = ['channel_id' => $event->channelId()];
        $rootId = $this->replyRootId($event);
        if ('' !== $rootId) {
            $payload['parent_id'] = $rootId;
        }

        $response = $this->request('POST', '/api/v4/users/me/typing', $payload);
        if (null !== $response && $response['status_code'] >= 400) {
            if ('O' === $event->channelType() && $this->isPermissionDeniedResponse($response) && $this->joinPublicChannel($event->channelId())) {
                $retryResponse = $this->request('POST', '/api/v4/users/me/typing', $payload);
                if (null === $retryResponse || $retryResponse['status_code'] < 400) {
                    return;
                }

                $response = $retryResponse;
            }

            if ($this->isPermissionDeniedResponse($response)) {
                $this->logger?->debug('Mattermost typing indicator was denied.', [
                    'method' => 'POST',
                    'path' => '/api/v4/users/me/typing',
                    'status_code' => $response['status_code'],
                    'channel_id' => $event->channelId(),
                    'channel_type' => $event->channelType(),
                ]);

                return;
            }

            $this->logFailedRequest('POST', '/api/v4/users/me/typing', $response, [
                'channel_id' => $event->channelId(),
                'channel_type' => $event->channelType(),
            ]);
        }
    }

    #[\Override]
    public function postProgress(MattermostInboundEvent $event, string $message): void
    {
        $this->createPost($event, $message);
    }

    #[\Override]
    public function uploadFile(MattermostInboundEvent $event, string $path): ?string
    {
        if (!$this->settings->enabled() || !is_file($path) || is_link($path)) {
            return null;
        }

        $response = $this->fileUploadRequest($event->channelId(), $path);
        if (null !== $response && $response['status_code'] >= 400
            && 'O' === $event->channelType()
            && $this->isPermissionDeniedResponse($response)
            && $this->joinPublicChannel($event->channelId())) {
            $response = $this->fileUploadRequest($event->channelId(), $path);
        }

        if (null === $response || $response['status_code'] >= 400) {
            $this->logFailedRequest('POST', '/api/v4/files', $response, [
                'channel_id' => $event->channelId(),
                'channel_type' => $event->channelType(),
                'filename' => basename($path),
            ]);

            return null;
        }

        try {
            $payload = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $payload = null;
        }
        $fileInfos = is_array($payload) ? ($payload['file_infos'] ?? null) : null;
        $firstFileInfo = is_array($fileInfos) ? ($fileInfos[0] ?? null) : null;
        $fileId = is_array($firstFileInfo) ? ($firstFileInfo['id'] ?? null) : null;
        if (!is_string($fileId) || '' === u($fileId)->trim()->toString()) {
            $this->logger?->warning('Mattermost file upload response did not contain a file ID.', [
                'channel_id' => $event->channelId(),
                'filename' => basename($path),
                'response_body' => $this->truncateResponseBody($response['body']),
            ]);

            return null;
        }

        return u($fileId)->trim()->toString();
    }

    /**
     * @param array<string, mixed> $props
     * @param list<string>         $fileIds
     */
    #[\Override]
    public function createPost(MattermostInboundEvent $event, string $message, array $props = [], array $fileIds = []): ?string
    {
        if (!$this->settings->enabled() || '' === u($message)->trim()->toString()) {
            return null;
        }

        $payload = [
            'channel_id' => $event->channelId(),
            'message' => u($message)->trim()->toString(),
        ];
        $rootId = $this->replyRootId($event);
        if ('' !== $rootId) {
            $payload['root_id'] = $rootId;
        }
        if ([] !== $props) {
            $payload['props'] = $props;
        }
        if ([] !== $fileIds) {
            $payload['file_ids'] = $fileIds;
        }

        $response = $this->request('POST', '/api/v4/posts', $payload);
        if (null === $response || $response['status_code'] < 400) {
            return $this->postId($response);
        }

        if (isset($payload['root_id']) && $this->isInvalidRootIdResponse($response)) {
            $rootId = $payload['root_id'];

            $resolvedRootId = $this->resolveThreadRootId($event);
            if (null !== $resolvedRootId && $resolvedRootId !== $rootId) {
                $payload['root_id'] = $resolvedRootId;

                $this->logger?->warning('Mattermost rejected a threaded post root; retrying with the root resolved from the source post.', [
                    'channel_id' => $event->channelId(),
                    'channel_type' => $event->channelType(),
                    'root_id' => $rootId,
                    'resolved_root_id' => $resolvedRootId,
                    'source_post_id' => $event->postId(),
                    'status_code' => $response['status_code'],
                    'response_body' => $this->truncateResponseBody($response['body']),
                ]);

                $resolvedResponse = $this->request('POST', '/api/v4/posts', $payload);
                if (null === $resolvedResponse || $resolvedResponse['status_code'] < 400) {
                    return $this->postId($resolvedResponse);
                }

                $response = $resolvedResponse;
            }

            unset($payload['root_id']);

            $this->logger?->warning('Mattermost rejected a threaded post root; retrying as a channel post.', [
                'channel_id' => $event->channelId(),
                'channel_type' => $event->channelType(),
                'root_id' => $rootId,
                'status_code' => $response['status_code'],
                'response_body' => $this->truncateResponseBody($response['body']),
            ]);

            $fallbackResponse = $this->request('POST', '/api/v4/posts', $payload);
            if (null === $fallbackResponse || $fallbackResponse['status_code'] < 400) {
                return $this->postId($fallbackResponse);
            }

            $this->logFailedRequest('POST', '/api/v4/posts', $fallbackResponse, [
                'channel_id' => $event->channelId(),
                'channel_type' => $event->channelType(),
                'fallback_after_invalid_root_id' => true,
            ]);

            return null;
        }

        $this->logFailedRequest('POST', '/api/v4/posts', $response, [
            'channel_id' => $event->channelId(),
            'channel_type' => $event->channelType(),
        ]);

        return null;
    }

    /** @param array<string, mixed> $props */
    #[\Override]
    public function updatePost(string $postId, string $message, array $props = []): bool
    {
        if (!$this->settings->enabled() || '' === u($postId)->trim()->toString() || '' === u($message)->trim()->toString()) {
            return false;
        }

        $payload = ['message' => u($message)->trim()->toString()];
        if ([] !== $props) {
            $payload['props'] = $props;
        }
        $path = sprintf('/api/v4/posts/%s/patch', rawurlencode($postId));
        $response = $this->request('PUT', $path, $payload);
        if (null !== $response && $response['status_code'] < 400) {
            return true;
        }

        $this->logFailedRequest('PUT', $path, $response, ['post_id' => $postId]);

        return false;
    }

    /** @param array{status_code: int, body: string}|null $response */
    private function postId(?array $response): ?string
    {
        if (null === $response) {
            return null;
        }

        $payload = json_decode($response['body'], true);
        $postId = is_array($payload) ? ($payload['id'] ?? null) : null;

        return is_string($postId) && '' !== u($postId)->trim()->toString() ? u($postId)->trim()->toString() : null;
    }

    private function resolveThreadRootId(MattermostInboundEvent $event): ?string
    {
        if ('' === $event->postId()) {
            return null;
        }

        $path = sprintf('/api/v4/posts/%s', rawurlencode($event->postId()));
        $response = $this->request('GET', $path);
        if (null === $response || $response['status_code'] >= 400) {
            $this->logFailedRequest('GET', $path, $response, [
                'channel_id' => $event->channelId(),
                'channel_type' => $event->channelType(),
                'operation' => 'resolve_thread_root',
            ]);

            return null;
        }

        try {
            $payload = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->logger?->warning('Mattermost API returned an invalid source post payload while resolving thread root.', [
                'path' => $path,
                'response_body' => $this->truncateResponseBody($response['body']),
            ]);

            return null;
        }

        if (!is_array($payload)) {
            return null;
        }

        $channelId = $payload['channel_id'] ?? null;
        if (is_string($channelId) && '' !== $channelId && $channelId !== $event->channelId()) {
            $this->logger?->warning('Mattermost source post channel does not match the inbound event channel.', [
                'event_channel_id' => $event->channelId(),
                'source_post_channel_id' => $channelId,
                'source_post_id' => $event->postId(),
            ]);

            return null;
        }

        $rootId = $payload['root_id'] ?? null;
        if (is_string($rootId) && '' !== u($rootId)->trim()->toString()) {
            return u($rootId)->trim()->toString();
        }

        $postId = $payload['id'] ?? null;
        if (is_string($postId) && '' !== u($postId)->trim()->toString()) {
            return u($postId)->trim()->toString();
        }

        return null;
    }

    private function joinPublicChannel(string $channelId): bool
    {
        $botUserId = $this->botUserId();
        if (null === $botUserId) {
            return false;
        }

        $path = sprintf('/api/v4/channels/%s/members', rawurlencode($channelId));
        $response = $this->request('POST', $path, ['user_id' => $botUserId]);
        if (null !== $response && $response['status_code'] < 400) {
            $this->logger?->debug('Mattermost bot joined public channel before sending typing indicator.', [
                'channel_id' => $channelId,
            ]);

            return true;
        }

        $this->logger?->debug('Mattermost bot could not join public channel before sending typing indicator.', [
            'channel_id' => $channelId,
            'status_code' => $response['status_code'] ?? null,
            'response_body' => null === $response ? null : $this->truncateResponseBody($response['body']),
        ]);

        return false;
    }

    private function botUserId(): ?string
    {
        if (null !== $this->botUserId) {
            return $this->botUserId;
        }

        $response = $this->request('GET', '/api/v4/users/me');
        if (null === $response || $response['status_code'] >= 400) {
            $this->logger?->debug('Mattermost bot user could not be loaded before sending typing indicator.', [
                'status_code' => $response['status_code'] ?? null,
                'response_body' => null === $response ? null : $this->truncateResponseBody($response['body']),
            ]);

            return null;
        }

        try {
            $payload = json_decode($response['body'], true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->logger?->debug('Mattermost API returned an invalid bot user payload before sending typing indicator.', [
                'response_body' => $this->truncateResponseBody($response['body']),
            ]);

            return null;
        }

        if (!is_array($payload) || !is_string($payload['id'] ?? null) || '' === u($payload['id'])->trim()->toString()) {
            $this->logger?->debug('Mattermost API returned a bot user payload without an id before sending typing indicator.', [
                'response_body' => $this->truncateResponseBody($response['body']),
            ]);

            return null;
        }

        $this->botUserId = u($payload['id'])->trim()->toString();

        return $this->botUserId;
    }

    /** @return array{status_code: int, body: string}|null */
    private function fileUploadRequest(string $channelId, string $path): ?array
    {
        $stream = fopen($path, 'rb');
        if (false === $stream) {
            return null;
        }

        try {
            $response = $this->httpClient->request('POST', $this->settings->baseUrl().'/api/v4/files', [
                'auth_bearer' => $this->settings->botToken(),
                'headers' => ['Accept' => 'application/json'],
                'body' => [
                    'channel_id' => $channelId,
                    'files' => $stream,
                ],
            ]);

            return [
                'status_code' => $response->getStatusCode(),
                'body' => $response->getContent(false),
            ];
        } catch (TransportExceptionInterface) {
            $this->logger?->warning('Mattermost API request failed due to a transport error.', [
                'method' => 'POST',
                'path' => '/api/v4/files',
                'filename' => basename($path),
            ]);

            return null;
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{status_code: int, body: string}|null
     */
    private function request(string $method, string $path, array $payload = []): ?array
    {
        try {
            $options = [
                'auth_bearer' => $this->settings->botToken(),
                'headers' => ['Accept' => 'application/json'],
            ];
            if ([] !== $payload) {
                $options['json'] = $payload;
            }

            $response = $this->httpClient->request($method, $this->settings->baseUrl().$path, $options);

            return [
                'status_code' => $response->getStatusCode(),
                'body' => $response->getContent(false),
            ];
        } catch (TransportExceptionInterface) {
            $this->logger?->warning('Mattermost API request failed due to a transport error.', [
                'method' => $method,
                'path' => $path,
            ]);

            return null;
        }
    }

    /**
     * @param array{status_code: int, body: string}|null $response
     * @param array<string, mixed>                       $extraContext
     */
    private function logFailedRequest(string $method, string $path, ?array $response, array $extraContext = []): void
    {
        if (null === $response) {
            return;
        }

        $context = [
            'method' => $method,
            'path' => $path,
            'status_code' => $response['status_code'],
            ...$extraContext,
        ];

        $responseBody = u($response['body'])->trim()->toString();
        if ('' !== $responseBody) {
            $context['response_body'] = $this->truncateResponseBody($responseBody);
        }

        $this->logger?->warning('Mattermost API request failed.', $context);
    }

    private function truncateResponseBody(string $body): string
    {
        if (u($body)->length() <= 2000) {
            return $body;
        }

        return u($body)->slice(0, 2000)->append('...')->toString();
    }

    /**
     * @param array{status_code: int, body: string} $response
     */
    private function isInvalidRootIdResponse(array $response): bool
    {
        if (400 !== $response['status_code']) {
            return false;
        }

        return u($response['body'])->containsAny([
            'api.post.create_post.root_id.app_error',
            'Invalid RootId parameter',
        ]);
    }

    /**
     * @param array{status_code: int, body: string} $response
     */
    private function isPermissionDeniedResponse(array $response): bool
    {
        if (403 !== $response['status_code']) {
            return false;
        }

        return u($response['body'])->containsAny([
            'api.context.permissions.app_error',
            'You do not have the appropriate permissions',
        ]);
    }

    private function replyRootId(MattermostInboundEvent $event): string
    {
        if ('' !== $event->rootId()) {
            return $event->rootId();
        }

        if (in_array($event->channelType(), ['D', 'G'], true)) {
            return '';
        }

        return $event->postId();
    }
}
