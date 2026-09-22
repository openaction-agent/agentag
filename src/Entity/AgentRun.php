<?php

namespace App\Entity;

use App\AgentTag\Runner\TaskModelSelection;
use App\AgentTag\Runner\TokenUsage;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

use function Symfony\Component\String\u;

#[ORM\Entity]
#[ORM\Table(name: 'agent_run')]
class AgentRun
{
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_RUNNING = 'running';
    public const STATUS_WAITING = 'waiting';
    public const STATUS_INTERRUPT_REQUESTED = 'interrupt_requested';
    public const STATUS_INTERRUPTED = 'interrupted';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public const INTERRUPT_CANCEL = 'cancel';
    public const INTERRUPT_STEER = 'steer';

    public const WORKSPACE_CLEANUP_RETAINED = 'retained';
    public const WORKSPACE_CLEANUP_CLEANED = 'cleaned';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** @var Collection<int, RunEvent> */
    #[ORM\OneToMany(targetEntity: RunEvent::class, mappedBy: 'run')]
    private Collection $events;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $title = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $acknowledgement = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $modelRoute = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $modelSelectionReason = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $taskPostId = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $answerPostId = null;

    /** @var array<string, string> */
    #[ORM\Column(type: 'json')]
    private array $mattermostFileIds = [];

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $inputPostIds = [];

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $requesterName = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $stoppedByName = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $codexThreadId = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $currentStage = null;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    private array $completedStages = [];

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $pendingSteering = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $interruptionKind = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $retainedUntil = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $wakeAt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $waitReason = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deadlineAt = null;

    #[ORM\Column]
    private int $attempt = 0;

    #[ORM\Column]
    private int $maxRetries = 2;

    #[ORM\Column]
    private int $retryDelaySeconds = 60;

    #[ORM\Column(length: 24)]
    private string $notificationPreference = 'milestones';

    /**
     * @param list<mixed> $artifacts
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: ChatSession::class, inversedBy: 'runs')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private ChatSession $session,
        #[ORM\Column(length: 32)]
        private string $status,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(type: 'text', nullable: true)]
        private ?string $inputSummary = null,
        #[ORM\Column(type: 'text', nullable: true)]
        private ?string $outputSummary = null,
        #[ORM\Column(type: 'text', nullable: true)]
        private ?string $contextSnapshot = null,
        #[ORM\Column(length: 120, nullable: true)]
        private ?string $workflowName = null,
        #[ORM\Column(length: 120, nullable: true)]
        private ?string $workflowVersion = null,
        #[ORM\Column(length: 120, nullable: true)]
        private ?string $workflowRevision = null,
        #[ORM\Column(length: 160, nullable: true)]
        private ?string $sourceEventId = null,
        #[ORM\Column(length: 120, nullable: true)]
        private ?string $requesterId = null,
        #[ORM\Column(type: 'text', nullable: true)]
        private ?string $workspacePath = null,
        /** @var list<mixed> */
        #[ORM\Column(type: 'json')]
        private array $artifacts = [],
        #[ORM\Column(length: 32)]
        private string $workspaceCleanupState = self::WORKSPACE_CLEANUP_RETAINED,
        #[ORM\Column(type: 'text', nullable: true)]
        private ?string $logSummary = null,
        #[ORM\Column(nullable: true)]
        private ?int $exitCode = null,
        #[ORM\Column(nullable: true)]
        private ?int $inputTokens = null,
        #[ORM\Column(nullable: true)]
        private ?int $outputTokens = null,
        #[ORM\Column(nullable: true)]
        private ?int $totalTokens = null,
    ) {
        $this->events = new ArrayCollection();
        if (null !== $this->sourceEventId) {
            $this->addInputPostId($this->sourceEventId);
        }
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function session(): ChatSession
    {
        return $this->session;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function startedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function finishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function inputSummary(): ?string
    {
        return $this->inputSummary;
    }

    public function contextSnapshot(): ?string
    {
        return $this->contextSnapshot;
    }

    public function outputSummary(): ?string
    {
        return $this->outputSummary;
    }

    public function workflowName(): ?string
    {
        return $this->workflowName;
    }

    public function workflowVersion(): ?string
    {
        return $this->workflowVersion;
    }

    public function workflowRevision(): ?string
    {
        return $this->workflowRevision;
    }

    public function sourceEventId(): ?string
    {
        return $this->sourceEventId;
    }

    /** @return list<string> */
    public function inputPostIds(): array
    {
        return $this->inputPostIds;
    }

    public function addInputPostId(string $postId): void
    {
        $postId = u($postId)->trim()->toString();
        if ('' !== $postId && !in_array($postId, $this->inputPostIds, true)) {
            $this->inputPostIds[] = $postId;
        }
    }

    public function requesterId(): ?string
    {
        return $this->requesterId;
    }

    public function requesterName(): ?string
    {
        return $this->requesterName;
    }

    public function stoppedByName(): ?string
    {
        return $this->stoppedByName;
    }

    public function workspacePath(): ?string
    {
        return $this->workspacePath;
    }

    /** @return list<string> */
    public function artifacts(): array
    {
        $paths = [];
        foreach ($this->artifacts as $artifact) {
            if (is_string($artifact)) {
                $paths[] = $artifact;
            } elseif (is_array($artifact) && is_string($artifact['path'] ?? null)) {
                $paths[] = $artifact['path'];
            }
        }

        return $paths;
    }

    /** @return list<array{path: string, name: string, size: int, sha256: string}> */
    public function replyArtifacts(): array
    {
        $artifacts = [];
        foreach ($this->artifacts as $artifact) {
            if (!is_array($artifact)
                || !is_string($artifact['path'] ?? null)
                || !is_string($artifact['name'] ?? null)
                || !is_int($artifact['size'] ?? null)
                || !is_string($artifact['sha256'] ?? null)) {
                continue;
            }

            $artifacts[] = [
                'path' => $artifact['path'],
                'name' => $artifact['name'],
                'size' => $artifact['size'],
                'sha256' => $artifact['sha256'],
            ];
        }

        return $artifacts;
    }

    public function workspaceCleanupState(): string
    {
        return $this->workspaceCleanupState;
    }

    /** @return Collection<int, RunEvent> */
    public function events(): Collection
    {
        return $this->events;
    }

    public function logSummary(): ?string
    {
        return $this->logSummary;
    }

    public function exitCode(): ?int
    {
        return $this->exitCode;
    }

    public function inputTokens(): ?int
    {
        return $this->inputTokens;
    }

    public function outputTokens(): ?int
    {
        return $this->outputTokens;
    }

    public function totalTokens(): ?int
    {
        return $this->totalTokens;
    }

    public function title(): string
    {
        return $this->title ?? 'Working on your request';
    }

    public function acknowledgement(): ?string
    {
        return $this->acknowledgement;
    }

    public function modelSelection(): TaskModelSelection
    {
        return TaskModelSelection::fromRoute($this->modelRoute ?? '', $this->modelSelectionReason ?? '')
            ?? TaskModelSelection::fallback();
    }

    public function hasModelSelection(): bool
    {
        return null !== TaskModelSelection::fromRoute($this->modelRoute ?? '', $this->modelSelectionReason ?? '');
    }

    public function taskPostId(): ?string
    {
        return $this->taskPostId;
    }

    public function answerPostId(): ?string
    {
        return $this->answerPostId;
    }

    public function codexThreadId(): ?string
    {
        return $this->codexThreadId;
    }

    public function currentStage(): ?string
    {
        return $this->currentStage;
    }

    /** @return list<string> */
    public function completedStages(): array
    {
        return $this->completedStages;
    }

    public function pendingSteering(): ?string
    {
        return $this->pendingSteering;
    }

    public function interruptionKind(): ?string
    {
        return $this->interruptionKind;
    }

    public function retainedUntil(): ?\DateTimeImmutable
    {
        return $this->retainedUntil;
    }

    public function wakeAt(): ?\DateTimeImmutable
    {
        return $this->wakeAt;
    }

    public function waitReason(): ?string
    {
        return $this->waitReason;
    }

    public function deadlineAt(): ?\DateTimeImmutable
    {
        return $this->deadlineAt;
    }

    public function attempt(): int
    {
        return $this->attempt;
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

    public function initializeTask(
        string $title,
        string $acknowledgement,
        ?string $requesterName,
        \DateTimeImmutable $deadlineAt,
        int $maxRetries,
        int $retryDelaySeconds,
        string $notificationPreference,
        ?TaskModelSelection $modelSelection = null,
    ): void {
        $this->configureTask($requesterName, $deadlineAt, $maxRetries, $retryDelaySeconds, $notificationPreference);
        $this->presentTask($title, $acknowledgement, $modelSelection);
    }

    public function configureTask(
        ?string $requesterName,
        \DateTimeImmutable $deadlineAt,
        int $maxRetries,
        int $retryDelaySeconds,
        string $notificationPreference,
    ): void {
        $this->requesterName = $requesterName;
        $this->deadlineAt = $deadlineAt;
        $this->maxRetries = $maxRetries;
        $this->retryDelaySeconds = $retryDelaySeconds;
        $this->notificationPreference = $notificationPreference;
    }

    public function presentTask(string $title, string $acknowledgement, ?TaskModelSelection $modelSelection = null): void
    {
        $modelSelection ??= TaskModelSelection::mainLuna();
        $this->title = $title;
        $this->acknowledgement = $acknowledgement;
        $this->modelRoute = $modelSelection->route;
        $this->modelSelectionReason = $modelSelection->reason;
        $this->currentStage = $acknowledgement;
    }

    public function acknowledgeTask(string $title, string $acknowledgement): void
    {
        $this->title = $title;
        $this->acknowledgement = $acknowledgement;
        $this->modelRoute = null;
        $this->modelSelectionReason = null;
        $this->currentStage = $acknowledgement;
    }

    public function selectModel(TaskModelSelection $modelSelection): void
    {
        $this->modelRoute = $modelSelection->route;
        $this->modelSelectionReason = $modelSelection->reason;
        $this->updateStage('Model selected. Starting the task.');
    }

    public function assignTaskPost(string $postId): void
    {
        $this->taskPostId = $postId;
    }

    public function assignAnswerPost(string $postId): void
    {
        $this->answerPostId = $postId;
    }

    public function mattermostFileId(string $artifactKey): ?string
    {
        $fileId = $this->mattermostFileIds[$artifactKey] ?? null;

        return is_string($fileId) && '' !== u($fileId)->trim()->toString() ? $fileId : null;
    }

    public function recordMattermostFileId(string $artifactKey, string $fileId): void
    {
        if ('' === u($artifactKey)->trim()->toString() || '' === u($fileId)->trim()->toString()) {
            return;
        }

        $this->mattermostFileIds[$artifactKey] = u($fileId)->trim()->toString();
    }

    public function changeNotificationPreference(string $preference): void
    {
        if (in_array($preference, ['all', 'milestones', 'completion'], true)) {
            $this->notificationPreference = $preference;
        }
    }

    public function recordCodexThread(string $threadId): void
    {
        $this->codexThreadId = $threadId;
    }

    public function markRunning(): void
    {
        $this->status = self::STATUS_RUNNING;
        $this->startedAt ??= new \DateTimeImmutable();
        $this->wakeAt = null;
        $this->waitReason = null;
        $this->interruptionKind = null;
        ++$this->attempt;
    }

    public function updateStage(string $stage): void
    {
        $stage = u($stage)->replaceMatches('/\s+/', ' ')->trim()->toString();
        if ('' === $stage || $stage === $this->currentStage) {
            return;
        }

        if (null !== $this->currentStage && !in_array($this->currentStage, $this->completedStages, true)) {
            $this->completedStages[] = $this->currentStage;
        }
        $this->currentStage = u($stage)->slice(0, 240)->toString();
    }

    public function requestCancellation(?string $stoppedByName = null): void
    {
        if ($this->isTerminal()) {
            return;
        }

        $stoppedByName = u($stoppedByName ?? '')->trim()->trimStart('@')->toString();
        if ('' !== $stoppedByName) {
            $this->stoppedByName = u($stoppedByName)->slice(0, 120)->toString();
        }

        if (!in_array($this->status, [self::STATUS_RUNNING, self::STATUS_INTERRUPT_REQUESTED], true)) {
            $this->markInterrupted('Task stopped before the next command started.', $this->workspacePath);

            return;
        }

        $this->updateStage('Stopping after the current command');
        $this->interruptionKind = self::INTERRUPT_CANCEL;
        $this->status = self::STATUS_INTERRUPT_REQUESTED;
    }

    public function requestSteering(string $instruction): void
    {
        $instruction = u($instruction)->trim()->toString();
        if ('' === $instruction || (self::STATUS_INTERRUPT_REQUESTED === $this->status && self::INTERRUPT_CANCEL === $this->interruptionKind)) {
            return;
        }
        $this->pendingSteering = null === $this->pendingSteering
            ? $instruction
            : $this->pendingSteering."\n\n".$instruction;
        $this->interruptionKind = self::INTERRUPT_STEER;
        $this->status = in_array($this->status, [self::STATUS_RUNNING, self::STATUS_INTERRUPT_REQUESTED], true)
            ? self::STATUS_INTERRUPT_REQUESTED
            : self::STATUS_ACCEPTED;
    }

    public function takePendingSteering(): ?string
    {
        $steering = $this->pendingSteering;
        $this->pendingSteering = null;

        return $steering;
    }

    public function prepareRetry(string $instruction): void
    {
        $this->pendingSteering = u($instruction)->trim()->toString();
        $this->status = self::STATUS_ACCEPTED;
        $this->answerPostId = null;
        $this->finishedAt = null;
        $this->exitCode = null;
        $this->interruptionKind = null;
        $this->wakeAt = null;
        $this->waitReason = null;
    }

    public function waitUntil(\DateTimeImmutable $wakeAt, string $reason): void
    {
        $this->status = self::STATUS_WAITING;
        $this->wakeAt = $wakeAt;
        $this->waitReason = $reason;
        $this->updateStage($reason);
    }

    public function wake(): void
    {
        $this->status = self::STATUS_ACCEPTED;
        $this->wakeAt = null;
        $this->waitReason = null;
    }

    /** @param list<string|array{path: string, name: string, size: int, sha256: string}> $artifacts */
    public function recordRunnerResult(
        string $status,
        string $outputSummary,
        string $logSummary,
        string $workspacePath,
        array $artifacts,
        int $exitCode,
        ?TokenUsage $tokenUsage,
    ): void {
        $this->status = $status;
        $this->outputSummary = $outputSummary;
        $this->logSummary = $logSummary;
        $this->workspacePath = $workspacePath;
        $this->artifacts = $artifacts;
        $this->exitCode = $exitCode;
        $this->inputTokens = ($this->inputTokens ?? 0) + ($tokenUsage?->inputTokens() ?? 0);
        $this->outputTokens = ($this->outputTokens ?? 0) + ($tokenUsage?->outputTokens() ?? 0);
        $this->totalTokens = ($this->totalTokens ?? 0) + ($tokenUsage?->totalTokens() ?? 0);
        if ($this->isTerminal()) {
            $this->finishedAt = new \DateTimeImmutable();
            if (null !== $this->currentStage && !in_array($this->currentStage, $this->completedStages, true)) {
                $this->completedStages[] = $this->currentStage;
            }
            $this->currentStage = null;
        }
    }

    public function markInterrupted(string $summary, ?string $workspacePath = null): void
    {
        $this->status = self::STATUS_INTERRUPTED;
        $this->outputSummary = $summary;
        $this->logSummary = $summary;
        $this->exitCode = 130;
        $this->finishedAt = new \DateTimeImmutable();
        $this->retainedUntil = $this->finishedAt->modify('+24 hours');
        if (null !== $this->currentStage && !in_array($this->currentStage, $this->completedStages, true)) {
            $this->completedStages[] = $this->currentStage;
        }
        $this->currentStage = null;
        if (null !== $workspacePath) {
            $this->workspacePath = $workspacePath;
        }
    }

    public function interruptionRequested(): bool
    {
        return self::STATUS_INTERRUPT_REQUESTED === $this->status;
    }

    public function cancellationRequested(): bool
    {
        return self::INTERRUPT_CANCEL === $this->interruptionKind;
    }

    public function deadlineExceeded(?\DateTimeImmutable $now = null): bool
    {
        return null !== $this->deadlineAt && $this->deadlineAt <= ($now ?? new \DateTimeImmutable());
    }

    public function canRetry(): bool
    {
        return $this->attempt <= $this->maxRetries && !$this->deadlineExceeded();
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_ACCEPTED, self::STATUS_RUNNING, self::STATUS_WAITING, self::STATUS_INTERRUPT_REQUESTED], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_INTERRUPTED], true);
    }

    public function markWorkspaceCleaned(): void
    {
        $this->workspaceCleanupState = self::WORKSPACE_CLEANUP_CLEANED;
    }
}
