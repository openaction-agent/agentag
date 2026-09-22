<?php

namespace App\AgentTag\Configuration;

use function Symfony\Component\String\u;

final readonly class AgentTagSettings
{
    public function __construct(
        private string $tag,
        private string $workspacePath,
        private int $runTimeoutSeconds = 1200,
        private string $modelSelectionModel = 'gpt-5.6-luna',
        private int $modelSelectionTimeoutSeconds = 20,
        private int $taskDeadlineSeconds = 86400,
        private int $maxRetries = 2,
        private int $retryDelaySeconds = 60,
        private string $notificationPreference = 'milestones',
    ) {
        $this->assertValidTag($tag);
        $this->assertAbsolutePath($workspacePath, 'workspace path');
        if ($runTimeoutSeconds < 1) {
            throw new \InvalidArgumentException('AgentTag run timeout must be a positive integer.');
        }
        if ('' === u($modelSelectionModel)->trim()->toString()) {
            throw new \InvalidArgumentException('AgentTag model-selection model must not be blank.');
        }
        if ($modelSelectionTimeoutSeconds < 1 || $taskDeadlineSeconds < 1 || $maxRetries < 0 || $retryDelaySeconds < 1) {
            throw new \InvalidArgumentException('AgentTag task timing and retry settings are invalid.');
        }
        if (!in_array($notificationPreference, ['all', 'milestones', 'completion'], true)) {
            throw new \InvalidArgumentException('AgentTag notification preference must be all, milestones, or completion.');
        }
    }

    public function tag(): string
    {
        return $this->tag;
    }

    public function workspacePath(): string
    {
        return $this->normalizePath($this->workspacePath);
    }

    public function runTimeoutSeconds(): int
    {
        return $this->runTimeoutSeconds;
    }

    public function modelSelectionModel(): string
    {
        return $this->modelSelectionModel;
    }

    public function modelSelectionTimeoutSeconds(): int
    {
        return $this->modelSelectionTimeoutSeconds;
    }

    public function taskDeadlineSeconds(): int
    {
        return $this->taskDeadlineSeconds;
    }

    public function maxRetries(): int
    {
        return $this->maxRetries;
    }

    public function retryDelaySeconds(): int
    {
        return $this->retryDelaySeconds;
    }

    public function notificationPreference(): string
    {
        return $this->notificationPreference;
    }

    private function assertValidTag(string $tag): void
    {
        if (!preg_match('/^@[A-Za-z][A-Za-z0-9_-]{1,63}$/', $tag)) {
            throw new \InvalidArgumentException(sprintf('AgentTag tag must look like @Codex, got "%s".', $tag));
        }
    }

    private function assertAbsolutePath(string $path, string $label): void
    {
        if (!u($path)->startsWith('/')) {
            throw new \InvalidArgumentException(sprintf('AgentTag %s must be absolute, got "%s".', $label, $path));
        }
    }

    private function normalizePath(string $path): string
    {
        return u($path)->trimEnd('/')->toString();
    }
}
