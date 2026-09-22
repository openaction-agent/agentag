<?php

namespace App\MessageHandler;

use App\AgentTag\Agent\AgentProfileProvider;
use App\AgentTag\Mattermost\MattermostInputFileDownloader;
use App\AgentTag\Mattermost\MattermostRunProgressSink;
use App\AgentTag\Mattermost\MattermostRunProgressSinkFactory;
use App\AgentTag\Run\AgentRunExecutionLock;
use App\AgentTag\Run\AgentRunTurnGate;
use App\AgentTag\Runner\AgentRunOrchestrator;
use App\AgentTag\Runner\AgentRunPromptBuilder;
use App\AgentTag\Workspace\WorkspaceLayout;
use App\Entity\AgentRun;
use App\Message\RunAgentRunMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\RecoverableMessageHandlingException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

use function Symfony\Component\String\u;

#[AsMessageHandler]
final readonly class RunAgentRunMessageHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AgentProfileProvider $agentProfileProvider,
        private AgentRunPromptBuilder $promptBuilder,
        private AgentRunOrchestrator $orchestrator,
        private MattermostRunProgressSinkFactory $mattermostProgressSinkFactory,
        private AgentRunTurnGate $turnGate,
        private MessageBusInterface $messageBus,
        private AgentRunExecutionLock $executionLock,
        private MattermostInputFileDownloader $inputFileDownloader,
        private WorkspaceLayout $workspaceLayout,
    ) {
    }

    public function __invoke(RunAgentRunMessage $message): void
    {
        $lease = $this->executionLock->acquire($message->runId());
        if (null === $lease) {
            throw new RecoverableMessageHandlingException(sprintf('Run #%d is already executing in another worker.', $message->runId()), retryDelay: 5000);
        }

        try {
            $this->run($message);
        } finally {
            $lease->release();
        }
    }

    private function run(RunAgentRunMessage $message): void
    {
        $run = $this->entityManager->getRepository(AgentRun::class)->find($message->runId());
        if (!$run instanceof AgentRun) {
            return;
        }

        $progressSink = $this->mattermostProgressSinkFactory->create($run);
        if ($run->isTerminal()) {
            $progressSink->finish();

            return;
        }
        if ($run->deadlineExceeded()) {
            $this->fail($run, 'Task deadline exceeded before the next stage could start.', $progressSink);

            return;
        }
        if (AgentRun::STATUS_WAITING === $run->status()) {
            $wakeAt = $run->wakeAt();
            if (null !== $wakeAt && $wakeAt > new \DateTimeImmutable()) {
                $this->schedule($run, $wakeAt->getTimestamp() - time());

                return;
            }
            $run->prepareRetry('Scheduled wake: '.($run->waitReason() ?? 're-check the pending external state.'));
            $this->entityManager->flush();
        }
        if (AgentRun::STATUS_RUNNING === $run->status()) {
            $run->prepareRetry('Resume after the worker process restarted. Inspect existing workspace state before continuing.');
            $this->entityManager->flush();
        }
        if ($run->interruptionRequested() && AgentRun::INTERRUPT_CANCEL === $run->interruptionKind()) {
            $run->markInterrupted('Task stopped before the next stage started.', $run->workspacePath());
            $this->entityManager->flush();
            $progressSink->finish();

            return;
        }
        if (AgentRun::STATUS_ACCEPTED !== $run->status()) {
            return;
        }

        $agent = $this->agentProfileProvider->profile();
        $workspacePath = $run->workspacePath();
        if (null === $workspacePath) {
            throw new \RuntimeException(sprintf('Task #%d has no session workspace path.', $message->runId()));
        }
        if (!$this->turnGate->waitForTurn($run, $agent->timeoutSeconds() + 30, $progressSink->onHeartbeat(...))) {
            $this->fail($run, 'Task could not start because an earlier task in this thread is still active.', $progressSink);

            return;
        }

        try {
            $this->inputFileDownloader->sync(
                $run->inputPostIds(),
                $this->workspaceLayout->inputFilesPath(sprintf('run-%d', $message->runId())),
            );
        } catch (\RuntimeException $exception) {
            $this->fail($run, 'Could not prepare Mattermost input files: '.$exception->getMessage(), $progressSink);

            return;
        }

        $steering = $run->takePendingSteering();
        $this->entityManager->flush();
        $this->orchestrator->run(
            $run,
            sprintf('run-%d', $message->runId()),
            $this->promptBuilder->build($run, $steering),
            $agent->runnerMode(),
            $agent->timeoutSeconds(),
            [],
            $progressSink,
        );

        $this->entityManager->refresh($run);
        $progressSink->finish();
        if (AgentRun::STATUS_WAITING === $run->status() && null !== $run->wakeAt()) {
            $progressSink->milestone(sprintf('%s I’ll check again %s.', u((string) $run->outputSummary())->trimEnd(), $run->wakeAt()->format('Y-m-d H:i \U\T\C')));
            $this->schedule($run, max(1, $run->wakeAt()->getTimestamp() - time()));
        } elseif (AgentRun::STATUS_ACCEPTED === $run->status()) {
            $delay = AgentRun::INTERRUPT_STEER === $run->interruptionKind() ? 0 : $run->retryDelaySeconds();
            $this->schedule($run, $delay);
        }
    }

    private function schedule(AgentRun $run, int $delaySeconds): void
    {
        $runId = $run->id();
        if (null === $runId) {
            return;
        }
        $stamps = $delaySeconds > 0 ? [new DelayStamp($delaySeconds * 1000)] : [];
        $this->messageBus->dispatch(new RunAgentRunMessage($runId), $stamps);
    }

    private function fail(AgentRun $run, string $message, MattermostRunProgressSink $progressSink): void
    {
        $run->recordRunnerResult(AgentRun::STATUS_FAILED, $message, $message, $run->workspacePath() ?? '', [], 1, null);
        $this->entityManager->flush();
        $progressSink->finish();
    }
}
