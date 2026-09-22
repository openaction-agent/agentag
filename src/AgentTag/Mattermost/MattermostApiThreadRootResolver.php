<?php

namespace App\AgentTag\Mattermost;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function Symfony\Component\String\u;

final readonly class MattermostApiThreadRootResolver implements MattermostThreadRootResolver
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private MattermostApiSettings $settings,
        private ?LoggerInterface $logger = null,
    ) {
    }

    #[\Override]
    public function rootIdFor(MattermostInboundEvent $event): string
    {
        if ('' !== $event->rootId()) {
            return $event->rootId();
        }
        if (!$this->settings->enabled() || '' === $event->postId()) {
            return $event->postId();
        }

        $path = sprintf('/api/v4/posts/%s', rawurlencode($event->postId()));

        try {
            $response = $this->httpClient->request('GET', $this->settings->baseUrl().$path, [
                'auth_bearer' => $this->settings->botToken(),
                'headers' => ['Accept' => 'application/json'],
            ]);
            if ($response->getStatusCode() >= 400) {
                return $event->postId();
            }
            $payload = $response->toArray(false);
        } catch (TransportExceptionInterface|DecodingExceptionInterface) {
            return $event->postId();
        }

        $channelId = $payload['channel_id'] ?? null;
        if (is_string($channelId) && '' !== $channelId && $channelId !== $event->channelId()) {
            $this->logger?->warning('Mattermost source post channel does not match the inbound event while resolving its session.', [
                'event_channel_id' => $event->channelId(),
                'source_post_channel_id' => $channelId,
                'source_post_id' => $event->postId(),
            ]);

            return $event->postId();
        }

        $rootId = $payload['root_id'] ?? null;
        if (is_string($rootId) && '' !== u($rootId)->trim()->toString()) {
            return u($rootId)->trim()->toString();
        }

        $postId = $payload['id'] ?? null;

        return is_string($postId) && '' !== u($postId)->trim()->toString() ? u($postId)->trim()->toString() : $event->postId();
    }
}
