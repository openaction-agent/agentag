<?php

namespace App\AgentTag\Runner;

use App\AgentTag\Run\RunEventRecorder;
use App\AgentTag\Security\SensitiveTextRedactor;
use App\AgentTag\Workspace\WorkspaceLayout;
use App\Entity\AgentRun;
use App\Entity\RunEvent;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

use function Symfony\Component\String\u;

final readonly class AgentRunOrchestrator
{
    public function __construct(
        private AgentRunnerInterface $runner,
        private WorkspaceLayout $workspaceLayout,
        private SensitiveTextRedactor $redactor,
        private EntityManagerInterface $entityManager,
        private ?RunEventRecorder $runEventRecorder = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param array<string, string> $environment
     */
    public function run(
        AgentRun $run,
        string $runIdentifier,
        string $prompt,
        string $runnerMode,
        int $timeoutSeconds,
        array $environment = [],
        ?AgentRunnerProgressSink $progressSink = null,
    ): AgentRunnerResult {
        $workingDirectory = $run->workspacePath() ?? $this->workspaceLayout->runPath($runIdentifier);
        $artifactsDirectory = $this->workspaceLayout->artifactsPath($runIdentifier);

        if (!is_dir($workingDirectory)) {
            mkdir($workingDirectory, 0777, true);
        }
        if (!is_dir($artifactsDirectory)) {
            mkdir($artifactsDirectory, 0777, true);
        }

        $this->runEventRecorder?->record($run, RunEvent::TYPE_WORKSPACE_PREPARED, 'Prepared run workspace.', [
            'working_directory' => $workingDirectory,
            'artifacts_directory' => $artifactsDirectory,
        ]);
        if ($run->interruptionRequested()) {
            $run->markInterrupted('Run interrupted before the agent runner started.', $workingDirectory);
            $this->entityManager->flush();
            $this->runEventRecorder?->record($run, RunEvent::TYPE_RUNNER_FINISHED, 'Agent runner skipped because interruption was requested.', [
                'status' => $run->status(),
                'exit_code' => $run->exitCode(),
                'workspace_path' => $workingDirectory,
            ]);

            return new AgentRunnerResult(130, '', '', '', [], null);
        }

        $run->markRunning();
        $this->entityManager->flush();
        $this->runEventRecorder?->record($run, null === $run->codexThreadId() ? RunEvent::TYPE_RUNNER_STARTED : RunEvent::TYPE_TASK_RESUMED, null === $run->codexThreadId() ? 'Started agent runner.' : 'Resumed Codex task session.', [
            'runner_mode' => $runnerMode,
            'timeout_seconds' => $timeoutSeconds,
        ]);
        $this->logger?->info('Starting agent runner.', [
            'run_id' => $run->id(),
            'working_directory' => $workingDirectory,
            'runner_mode' => $runnerMode,
        ]);
        $progressSink?->onHeartbeat();

        $modelSelection = $run->modelSelection();
        $result = $this->runner->run(new AgentRunnerInput(
            $prompt,
            $workingDirectory,
            $artifactsDirectory,
            $environment,
            $timeoutSeconds,
            $runnerMode,
            $progressSink,
            fn (): bool => $this->interruptionRequested($run),
            $run->codexThreadId(),
            function (string $sessionId) use ($run): void {
                $run->recordCodexThread($sessionId);
                $this->entityManager->flush();
            },
            $modelSelection->model,
            $modelSelection->effort,
        ));

        if (null !== $result->sessionId()) {
            $run->recordCodexThread($result->sessionId());
        }

        $status = $this->resultStatus($run, $result);
        $summary = match ($status) {
            AgentRun::STATUS_INTERRUPTED => 'Task stopped by request. The workspace is preserved for 24 hours.',
            AgentRun::STATUS_ACCEPTED => $run->interruptionRequested()
                ? 'Applying new steering to the same task.'
                : 'The stage failed and will be retried automatically.',
            default => $result->finalMessage(),
        };
        $run->recordRunnerResult(
            $status,
            $this->redactor->redact($summary),
            $this->redactor->redact($this->logSummary($result)),
            $workingDirectory,
            array_map(static fn (AgentArtifact $artifact): array => $artifact->metadata(), $result->artifacts()),
            $result->exitCode(),
            $result->tokenUsage(),
        );
        if (AgentRun::STATUS_INTERRUPTED === $status) {
            $run->markInterrupted($summary, $workingDirectory);
        } elseif (null !== $result->continuation()) {
            $run->waitUntil(
                new \DateTimeImmutable('+'.$result->continuation()->delaySeconds().' seconds'),
                $result->continuation()->reason(),
            );
            $this->runEventRecorder?->record($run, RunEvent::TYPE_TASK_WAITING, $result->continuation()->reason(), [
                'wake_at' => $run->wakeAt()?->format(\DateTimeInterface::ATOM),
                'delay_seconds' => $result->continuation()->delaySeconds(),
            ]);
        } elseif (AgentRun::STATUS_ACCEPTED === $status) {
            $run->updateStage($summary);
        }
        $this->entityManager->flush();

        $this->runEventRecorder?->record($run, RunEvent::TYPE_RUNNER_FINISHED, $result->successful() ? 'Agent runner completed.' : 'Agent runner failed.', [
            'status' => $run->status(),
            'exit_code' => $result->exitCode(),
            'workspace_path' => $workingDirectory,
        ]);
        if (null !== $result->tokenUsage()) {
            $this->runEventRecorder?->record($run, RunEvent::TYPE_TOKEN_USAGE, 'Recorded token usage.', [
                'input_tokens' => $result->tokenUsage()->inputTokens(),
                'output_tokens' => $result->tokenUsage()->outputTokens(),
                'total_tokens' => $result->tokenUsage()->totalTokens(),
            ]);
        }
        $this->logger?->info('Finished agent runner.', [
            'run_id' => $run->id(),
            'status' => $run->status(),
            'exit_code' => $run->exitCode(),
            'input_tokens' => $run->inputTokens(),
            'output_tokens' => $run->outputTokens(),
            'total_tokens' => $run->totalTokens(),
        ]);

        return $result;
    }

    private function logSummary(AgentRunnerResult $result): string
    {
        return u(sprintf(
            "stdout: %s\nstderr: %s",
            $result->stdout(),
            $result->stderr(),
        ))->trim()->toString();
    }

    private function interruptionRequested(AgentRun $run): bool
    {
        $this->entityManager->refresh($run);

        return $run->interruptionRequested() || $run->cancellationRequested();
    }

    private function resultStatus(AgentRun $run, AgentRunnerResult $result): string
    {
        if ($run->cancellationRequested()) {
            return AgentRun::STATUS_INTERRUPTED;
        }
        if ($run->interruptionRequested()) {
            return AgentRun::INTERRUPT_STEER === $run->interruptionKind()
                ? AgentRun::STATUS_ACCEPTED
                : AgentRun::STATUS_INTERRUPTED;
        }
        if (null !== $result->continuation() && $result->successful()) {
            return AgentRun::STATUS_WAITING;
        }
        if ($result->successful()) {
            return AgentRun::STATUS_COMPLETED;
        }

        return $run->canRetry() ? AgentRun::STATUS_ACCEPTED : AgentRun::STATUS_FAILED;
    }
}
