<?php

namespace App\AgentTag\Chat;

use function Symfony\Component\String\u;

final readonly class ChatSessionReference
{
    public function __construct(
        private string $teamId,
        private string $channelId,
        private string $threadId,
    ) {
    }

    public function key(): string
    {
        return u(':')->join(['mattermost', $this->teamId, $this->channelId, $this->threadId])->toString();
    }

    public function teamId(): string
    {
        return $this->teamId;
    }

    public function channelId(): string
    {
        return $this->channelId;
    }

    public function threadId(): string
    {
        return $this->threadId;
    }
}
