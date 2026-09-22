<?php

namespace App\AgentTag\Mattermost;

use App\AgentTag\Run\RunEventRecorder;
use App\AgentTag\Runner\AgentRunnerProgress;
use App\AgentTag\Runner\AgentRunnerProgressSink;
use App\AgentTag\Runner\ReplyArtifactCollector;
use App\Entity\AgentRun;
use App\Entity\RunEvent;
use Doctrine\ORM\EntityManagerInterface;

use function Symfony\Component\String\u;

final class MattermostRunProgressSink implements AgentRunnerProgressSink
{
    private int $lastUpdatedAt = 0;
    private int $lastTypingAt = 0;

    /** @var array<string, true> */
    private array $notifiedMcpFailures = [];

    public function __construct(
        private readonly MattermostNotifier $notifier,
        private readonly MattermostInboundEvent $event,
        private readonly AgentRun $run,
        private readonly TaskCardRenderer $renderer,
        private readonly EntityManagerInterface $entityManager,
        private readonly ?RunEventRecorder $runEventRecorder = null,
        private readonly int $minimumIntervalSeconds = 3,
        private readonly int $typingRefreshIntervalSeconds = 2,
    ) {
    }

    #[\Override]
    public function onProgress(AgentRunnerProgress $progress): void
    {
        if ('mcp_startup_failed' === $progress->type()) {
            $this->notifyMcpStartupFailure($progress);

            return;
        }
        if ('agent_message' !== $progress->type()) {
            return;
        }
        $stage = $this->stage($progress->message());
        if ('' === $stage || $stage === $this->run->currentStage()) {
            return;
        }

        $this->run->updateStage($stage);
        $this->entityManager->flush();
        $this->runEventRecorder?->record($this->run, RunEvent::TYPE_PROGRESS_UPDATE, $stage, [
            'platform' => 'mattermost',
            'progress_type' => $progress->type(),
        ]);
        if (0 === $this->lastUpdatedAt || time() - $this->lastUpdatedAt >= $this->minimumIntervalSeconds) {
            $this->updateCard();
        }
    }

    #[\Override]
    public function onHeartbeat(): void
    {
        $now = time();
        if (0 !== $this->lastTypingAt && $now - $this->lastTypingAt < $this->typingRefreshIntervalSeconds) {
            return;
        }
        $this->notifier->showTyping($this->event);
        $this->lastTypingAt = $now;
    }

    public function finish(): void
    {
        $this->updateCard();
        $this->publishAnswer();
    }

    public function milestone(string $message): void
    {
        if ('completion' !== $this->run->notificationPreference()) {
            $this->notifier->postProgress($this->event, $message);
        }
    }

    private function notifyMcpStartupFailure(AgentRunnerProgress $progress): void
    {
        $server = $progress->context()['server'] ?? null;
        $key = is_string($server) ? $server : $progress->message();
        if (isset($this->notifiedMcpFailures[$key])) {
            return;
        }
        $this->notifiedMcpFailures[$key] = true;

        $this->notifier->postProgress($this->event, '⚠️ '.$progress->message());
    }

    private function updateCard(): void
    {
        $postId = $this->run->taskPostId();
        if (null === $postId) {
            $card = $this->renderer->render($this->run);
            $postId = $this->notifier->createPost($this->event, $card->message, $card->props);
            if (null !== $postId) {
                $this->run->assignTaskPost($postId);
                $this->entityManager->flush();
            }
        } else {
            $card = $this->renderer->render($this->run);
            $this->notifier->updatePost($postId, $card->message, $card->props);
        }
        $this->lastUpdatedAt = time();
    }

    private function publishAnswer(): void
    {
        if (!in_array($this->run->status(), [AgentRun::STATUS_COMPLETED, AgentRun::STATUS_FAILED], true)
            || null !== $this->run->answerPostId()) {
            return;
        }

        $message = u($this->run->outputSummary() ?? '')->trim()->toString();
        if ('' === $message) {
            $message = AgentRun::STATUS_COMPLETED === $this->run->status()
                ? 'Task completed.'
                : 'The task could not be completed.';
        }
        $fileIds = $this->uploadReplyArtifacts();
        $postId = $this->notifier->createPost($this->event, $message, fileIds: $fileIds);
        if (null === $postId) {
            throw new \RuntimeException(sprintf('Mattermost final reply delivery failed for run #%d.', $this->run->id() ?? 0));
        }

        $this->run->assignAnswerPost($postId);
        $this->entityManager->flush();
    }

    /** @return list<string> */
    private function uploadReplyArtifacts(): array
    {
        $fileIds = [];
        foreach (array_slice($this->run->replyArtifacts(), 0, ReplyArtifactCollector::MAX_FILES) as $artifact) {
            $this->assertArtifactUnchanged($artifact);
            $artifactKey = hash('sha256', $artifact['name']."\0".$artifact['sha256']);
            $fileId = $this->run->mattermostFileId($artifactKey);
            if (null === $fileId) {
                $fileId = $this->notifier->uploadFile($this->event, $artifact['path']);
                if (null === $fileId) {
                    throw new \RuntimeException(sprintf('Mattermost could not upload reply artifact "%s" for run #%d.', $artifact['name'], $this->run->id() ?? 0));
                }

                $this->run->recordMattermostFileId($artifactKey, $fileId);
                $this->entityManager->flush();
            }

            $fileIds[] = $fileId;
        }

        return $fileIds;
    }

    /** @param array{path: string, name: string, size: int, sha256: string} $artifact */
    private function assertArtifactUnchanged(array $artifact): void
    {
        $path = $artifact['path'];
        $resolvedPath = realpath($path);
        $size = false === $resolvedPath ? false : filesize($resolvedPath);
        $sha256 = false === $resolvedPath ? false : hash_file('sha256', $resolvedPath);
        if (false === $resolvedPath
            || $resolvedPath !== $path
            || is_link($path)
            || !is_file($resolvedPath)
            || ReplyArtifactCollector::DIRECTORY !== basename(dirname($resolvedPath))
            || basename($resolvedPath) !== $artifact['name']
            || $size !== $artifact['size']
            || $sha256 !== $artifact['sha256']) {
            throw new \RuntimeException(sprintf('Reply artifact "%s" changed after collection and will not be uploaded.', $artifact['name']));
        }
    }

    private function stage(string $message): string
    {
        $message = u($message)->replaceMatches('/\s+/', ' ')->trim()->toString();
        $sentence = u($message)->split('/(?<=[.!?])\s+/', 2, 0)[0]->toString();

        return u($sentence)->slice(0, 240)->toString();
    }
}
