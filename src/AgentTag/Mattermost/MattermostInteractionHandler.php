<?php

namespace App\AgentTag\Mattermost;

use App\AgentTag\Agent\AgentProfileProvider;
use App\AgentTag\Chat\ConfiguredTagMentionDetector;
use App\AgentTag\Chat\InboundEventIdempotencyStore;
use App\AgentTag\Configuration\AgentTagSettings;
use App\AgentTag\Run\RunInterrupter;
use App\AgentTag\Session\ChatSessionStore;
use App\Entity\AgentRun;
use App\Message\PrepareAgentTaskMessage;
use App\Message\RunAgentRunMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

use function Symfony\Component\String\u;

final readonly class MattermostInteractionHandler
{
    public function __construct(
        private ConfiguredTagMentionDetector $mentionDetector,
        private MattermostSessionMapper $sessionMapper,
        private InboundEventIdempotencyStore $idempotencyStore,
        private MattermostNotifier $notifier,
        private ChatSessionStore $sessionStore,
        private MattermostThreadContextProvider $threadContextProvider,
        private AgentProfileProvider $agentProfileProvider,
        private MessageBusInterface $messageBus,
        private RunInterrupter $runInterrupter,
        private TaskCardRenderer $taskCardRenderer,
        private AgentTagSettings $settings,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function handle(MattermostInboundEvent $event): MattermostInteractionResult
    {
        if (!$this->mentionDetector->isMentioned($event->text())) {
            return MattermostInteractionResult::ignored();
        }
        if (!$this->idempotencyStore->remember('mattermost:'.$event->eventId())) {
            return MattermostInteractionResult::duplicate();
        }

        $this->notifier->showTyping($event);
        $session = $this->sessionMapper->map($event);
        $instruction = $this->instruction($event->text());

        if ($this->isStopCommand($instruction)) {
            $run = $this->runInterrupter->cancelActiveRun($session, $event->eventId(), $event->userId(), $event->userName());
            if (null !== $run) {
                if (null === $run->taskPostId()) {
                    $this->notifier->postProgress($event, 'Cancellation requested before the task started.');
                } else {
                    $this->refreshTaskCard($run);
                }
            }

            return MattermostInteractionResult::handled('');
        }

        if ($this->isRetryCommand($instruction)) {
            $run = $this->runInterrupter->retryLatestRun($session, $instruction);
            if (null !== $run) {
                $run->addInputPostId($event->postId());
                $this->sessionStore->save($run);
                $this->dispatch($run);
                $this->refreshTaskCard($run);

                return MattermostInteractionResult::handled('');
            }
        }

        $activeRun = $this->runInterrupter->steerActiveRun($session, $instruction, $event->eventId(), $event->userId());
        if (null !== $activeRun) {
            $activeRun->addInputPostId($event->postId());
            $preference = $this->requestedNotificationPreference($event->text());
            if (null !== $preference) {
                $activeRun->changeNotificationPreference($preference);
            }
            $this->sessionStore->save($activeRun);
            if (AgentRun::STATUS_ACCEPTED === $activeRun->status() && null !== $activeRun->wakeAt()) {
                $this->dispatch($activeRun);
            }
            $this->refreshTaskCard($activeRun);

            return MattermostInteractionResult::handled('');
        }

        try {
            $agent = $this->agentProfileProvider->profile();
            $run = $this->sessionStore->recordRun(
                $session,
                $event->text(),
                $this->threadContextProvider->contextFor($event, $session->threadId()),
                $agent,
                $event->eventId(),
                $event->userId(),
            );
            $run->configureTask(
                '' === $event->userName() ? null : $event->userName(),
                $this->deadline($event->text()),
                $this->settings->maxRetries(),
                $this->settings->retryDelaySeconds(),
                $this->notificationPreference($event->text()),
            );
            if ($run->hasModelSelection()) {
                $run->presentTask(
                    'Request received',
                    'Request received. Continuing with the model selected for this session.',
                    $run->modelSelection(),
                );
            } else {
                $run->acknowledgeTask('Request received', 'Request received. Deciding which model to use.');
            }
            $this->sessionStore->save($run);

            $card = $this->taskCardRenderer->render($run);
            $postId = $this->notifier->createPost($event, $card->message, $card->props);
            if (null !== $postId) {
                $run->assignTaskPost($postId);
                $this->sessionStore->save($run);
            }
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            $message = $exception->getMessage();
            $this->notifier->postProgress($event, $message);
            $this->logger?->warning('Rejected Mattermost task during configuration validation.', [
                'event_id' => $event->eventId(),
                'error' => $message,
            ]);

            return MattermostInteractionResult::handled($message);
        }

        $runId = $run->id();
        if (null === $runId) {
            throw new \LogicException('Recorded Mattermost tasks must have an id before preparation.');
        }
        $this->messageBus->dispatch($run->hasModelSelection()
            ? new RunAgentRunMessage($runId)
            : new PrepareAgentTaskMessage($runId));

        return MattermostInteractionResult::handled('');
    }

    private function dispatch(AgentRun $run): void
    {
        $runId = $run->id();
        if (null === $runId) {
            throw new \LogicException('Recorded Mattermost tasks must have an id before dispatch.');
        }
        $this->messageBus->dispatch(new RunAgentRunMessage($runId));
    }

    private function refreshTaskCard(AgentRun $run): void
    {
        if (null === $run->taskPostId()) {
            return;
        }
        $card = $this->taskCardRenderer->render($run);
        $this->notifier->updatePost($run->taskPostId(), $card->message, $card->props);
    }

    private function instruction(string $text): string
    {
        return u($text)->trim()->replaceMatches('/^@[A-Za-z][A-Za-z0-9_-]{1,63}[:,]?\s*/', '')->trim()->toString();
    }

    private function isStopCommand(string $instruction): bool
    {
        return in_array(u($instruction)->lower()->toString(), ['stop', 'stop please', 'please stop', 'cancel', 'cancel run', 'interrupt', 'abort', 'arrete', 'arrête', 'annule', 'stoppe'], true);
    }

    private function isRetryCommand(string $instruction): bool
    {
        return 1 === preg_match('/^(retry|resume)(?:\s|$)/i', $instruction);
    }

    private function notificationPreference(string $text): string
    {
        return $this->requestedNotificationPreference($text) ?? $this->settings->notificationPreference();
    }

    private function requestedNotificationPreference(string $text): ?string
    {
        if (preg_match('/(?:only\s+)?notify me (?:only )?(?:when (?:it is )?(?:done|complete)|on completion)/i', $text)) {
            return 'completion';
        }
        if (preg_match('/notify me (?:about |on )?(?:every|all) (?:update|step)/i', $text)) {
            return 'all';
        }

        return null;
    }

    private function deadline(string $text): \DateTimeImmutable
    {
        if (preg_match('/(?:deadline|due) in (\d+)\s*(minute|hour|day)s?/i', $text, $matches)) {
            $amount = min(365, max(1, (int) $matches[1]));

            return new \DateTimeImmutable(sprintf('+%d %ss', $amount, u($matches[2])->lower()));
        }

        return new \DateTimeImmutable('+'.$this->settings->taskDeadlineSeconds().' seconds');
    }
}
