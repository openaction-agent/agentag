<?php

namespace App\AgentTag\Agent;

use function Symfony\Component\String\u;

final readonly class AgentProfile
{
    public function __construct(
        private string $name,
        private string $workspacePath,
        private ?string $workspaceRevision,
        private string $runnerMode,
        private int $timeoutSeconds,
    ) {
        if ('' === u($name)->trim()->toString()) {
            throw new \InvalidArgumentException('Agent profile name must not be blank.');
        }

        if ($timeoutSeconds < 1) {
            throw new \InvalidArgumentException('Agent profile timeout must be a positive integer.');
        }
    }

    public function name(): string
    {
        return $this->name;
    }

    public function workspacePath(): string
    {
        return $this->workspacePath;
    }

    public function workspaceRevision(): ?string
    {
        return $this->workspaceRevision;
    }

    public function runnerMode(): string
    {
        return $this->runnerMode;
    }

    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }
}
