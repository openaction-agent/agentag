<?php

namespace App\AgentTag\Runner;

/**
 * Dispatches a task to the harness its selected route runs on.
 */
final readonly class RoutingAgentRunner implements AgentRunnerInterface
{
    public function __construct(
        private CodexCliRunner $codexRunner,
        private ClaudeCodeRunner $claudeRunner,
    ) {
    }

    #[\Override]
    public function run(AgentRunnerInput $input): AgentRunnerResult
    {
        return match ($input->runner()) {
            TaskModelSelection::RUNNER_CODEX => $this->codexRunner->run($input),
            TaskModelSelection::RUNNER_CLAUDE => $this->claudeRunner->run($input),
            default => throw new \InvalidArgumentException(sprintf('Unknown agent runner "%s".', $input->runner())),
        };
    }
}
