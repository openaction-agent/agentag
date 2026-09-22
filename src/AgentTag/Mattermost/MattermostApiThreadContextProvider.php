<?php

namespace App\AgentTag\Mattermost;

use App\AgentTag\Session\ChatThreadContext;
use App\AgentTag\Session\ChatThreadMessage;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function Symfony\Component\String\u;

final readonly class MattermostApiThreadContextProvider implements MattermostThreadContextProvider
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private MattermostApiSettings $settings,
    ) {
    }

    #[\Override]
    public function contextFor(MattermostInboundEvent $event, string $canonicalThreadId): ChatThreadContext
    {
        if (!$this->settings->enabled()) {
            return $this->fallbackContext($event);
        }

        $rootPostId = in_array($event->channelType(), ['D', 'G'], true)
            ? $event->postId()
            : $canonicalThreadId;

        try {
            $response = $this->httpClient->request(
                'GET',
                sprintf('%s/api/v4/posts/%s/thread', $this->settings->baseUrl(), rawurlencode($rootPostId)),
                [
                    'auth_bearer' => $this->settings->botToken(),
                    'headers' => ['Accept' => 'application/json'],
                ],
            );

            if ($response->getStatusCode() >= 400) {
                return $this->fallbackContext($event);
            }

            $payload = $response->toArray(false);
        } catch (TransportExceptionInterface|DecodingExceptionInterface) {
            return $this->fallbackContext($event);
        }

        $payload = $this->stringKeyedPayload($payload);
        if (null === $payload) {
            return $this->fallbackContext($event);
        }

        return $this->contextFromPayload($payload, $rootPostId, $event) ?? $this->fallbackContext($event);
    }

    private function fallbackContext(MattermostInboundEvent $event): ChatThreadContext
    {
        return ChatThreadContext::single($event->postId(), $event->userId(), $event->text());
    }

    /**
     * @param array<mixed, mixed> $payload
     *
     * @return array<string, mixed>|null
     */
    private function stringKeyedPayload(array $payload): ?array
    {
        $normalized = [];
        foreach ($payload as $key => $value) {
            if (!is_string($key)) {
                return null;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function contextFromPayload(array $payload, string $rootPostId, MattermostInboundEvent $event): ?ChatThreadContext
    {
        $posts = $payload['posts'] ?? null;
        if (!is_array($posts)) {
            return null;
        }

        $order = $payload['order'] ?? array_keys($posts);
        if (!is_array($order)) {
            return null;
        }

        $orderPositions = [];
        foreach ($order as $position => $postId) {
            if (is_string($postId)) {
                $orderPositions[$postId] = $position;
            }
        }

        $entries = [];

        foreach ($posts as $postId => $post) {
            if (!is_string($postId)) {
                continue;
            }
            if (!is_array($post)) {
                continue;
            }

            $message = $this->messageFromPost($postId, $post);
            if (null === $message) {
                continue;
            }

            $createdAt = $post['create_at'] ?? 0;
            $entries[] = [
                'message' => $message,
                'created_at' => is_int($createdAt) || is_float($createdAt) || is_string($createdAt) && is_numeric($createdAt)
                    ? (int) $createdAt
                    : 0,
                'order' => $orderPositions[$postId] ?? \PHP_INT_MAX,
            ];
        }

        if ([] === $entries) {
            return null;
        }

        usort($entries, static fn (array $left, array $right): int => [
            $left['created_at'],
            $left['order'],
            $left['message']->externalId(),
        ] <=> [
            $right['created_at'],
            $right['order'],
            $right['message']->externalId(),
        ]);

        $messagesById = [];
        $orderedMessages = [];
        foreach ($entries as $entry) {
            $message = $entry['message'];
            $messagesById[$message->externalId()] = $message;
            $orderedMessages[] = $message;
        }

        $rootMessage = $messagesById[$rootPostId] ?? null;
        $replyMessages = array_values(array_filter(
            $orderedMessages,
            static fn (ChatThreadMessage $message): bool => $message->externalId() !== $rootPostId,
        ));
        $currentMessage = $messagesById[$event->postId()]
            ?? new ChatThreadMessage($event->postId(), $event->userId(), $event->text());
        $replyMessages = array_values(array_filter(
            $replyMessages,
            static fn (ChatThreadMessage $message): bool => $message->externalId() !== $currentMessage->externalId(),
        ));
        $replyMessages[] = $currentMessage;
        $replyLimit = null === $rootMessage ? $this->settings->recentReplyLimit() : $this->settings->recentReplyLimit() - 1;
        $recentReplies = array_slice($replyMessages, -max(0, $replyLimit));

        return new ChatThreadContext([
            ...null === $rootMessage ? [] : [$rootMessage],
            ...$recentReplies,
        ]);
    }

    /**
     * @param array<mixed, mixed> $post
     */
    private function messageFromPost(string $postId, array $post): ?ChatThreadMessage
    {
        $message = $post['message'] ?? null;
        if (!is_scalar($message)) {
            return null;
        }

        $userId = $post['user_id'] ?? '';
        if (!is_scalar($userId)) {
            $userId = '';
        }

        return new ChatThreadMessage($postId, u((string) $userId)->trim()->toString(), u((string) $message)->trim()->toString());
    }
}
