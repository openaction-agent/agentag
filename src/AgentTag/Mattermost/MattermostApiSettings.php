<?php

namespace App\AgentTag\Mattermost;

use function Symfony\Component\String\u;

final readonly class MattermostApiSettings
{
    private string $baseUrl;

    private int $recentReplyLimit;

    public function __construct(
        string $baseUrl,
        private string $botToken,
        int $recentReplyLimit,
    ) {
        $this->baseUrl = u($baseUrl)->trimEnd('/')->toString();
        $this->recentReplyLimit = max(1, min(100, $recentReplyLimit));
    }

    public function enabled(): bool
    {
        return '' !== $this->baseUrl && '' !== $this->botToken;
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function botToken(): string
    {
        return $this->botToken;
    }

    public function recentReplyLimit(): int
    {
        return $this->recentReplyLimit;
    }
}
