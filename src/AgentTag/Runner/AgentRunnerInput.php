<?php

namespace App\AgentTag\Runner;

final readonly class AgentRunnerInput
{
    /**
     * @param array<string, string> $environment
     */
    public function __construct(
        private string $prompt,
        private string $workingDirectory,
        private string $artifactsDirectory,
        private array $environment,
        private int $timeoutSeconds,
        private string $runnerMode,
        private ?AgentRunnerProgressSink $progressSink = null,
        private ?\Closure $interruptionChecker = null,
        private ?string $resumeSessionId = null,
        private ?\Closure $sessionStartedCallback = null,
        private string $model = 'gpt-5.6-luna',
        private string $reasoningEffort = 'max',
        private string $runner = TaskModelSelection::RUNNER_CODEX,
    ) {
    }

    public function prompt(): string
    {
        return $this->prompt;
    }

    public function workingDirectory(): string
    {
        return $this->workingDirectory;
    }

    public function artifactsDirectory(): string
    {
        return $this->artifactsDirectory;
    }

    /**
     * @return array<string, string>
     */
    public function environment(): array
    {
        return $this->environment;
    }

    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    public function runnerMode(): string
    {
        return $this->runnerMode;
    }

    public function progressSink(): ?AgentRunnerProgressSink
    {
        return $this->progressSink;
    }

    public function interruptionRequested(): bool
    {
        return null !== $this->interruptionChecker && ($this->interruptionChecker)();
    }

    public function resumeSessionId(): ?string
    {
        return $this->resumeSessionId;
    }

    public function sessionStarted(string $sessionId): void
    {
        if (null !== $this->sessionStartedCallback) {
            ($this->sessionStartedCallback)($sessionId);
        }
    }

    public function model(): string
    {
        return $this->model;
    }

    public function reasoningEffort(): string
    {
        return $this->reasoningEffort;
    }

    public function runner(): string
    {
        return $this->runner;
    }
}
