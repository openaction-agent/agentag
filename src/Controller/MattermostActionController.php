<?php

namespace App\Controller;

use App\AgentTag\Mattermost\TaskCardRenderer;
use App\AgentTag\Run\RunEventRecorder;
use App\Entity\AgentRun;
use App\Entity\RunEvent;
use App\Message\RunAgentRunMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

use function Symfony\Component\String\u;

final class MattermostActionController extends AbstractController
{
    #[Route('/integrations/mattermost/action', name: 'agentag_mattermost_action', methods: ['POST'])]
    public function __invoke(
        Request $request,
        EntityManagerInterface $entityManager,
        TaskCardRenderer $renderer,
        MessageBusInterface $messageBus,
        Filesystem $filesystem,
        RunEventRecorder $runEventRecorder,
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true);
        $context = is_array($payload) && is_array($payload['context'] ?? null) ? $payload['context'] : [];
        $runId = $context['run_id'] ?? null;
        $action = $context['action'] ?? null;
        $signature = $context['signature'] ?? null;
        if (!is_int($runId) || !is_string($action) || !is_string($signature)
            || !hash_equals($renderer->signature($runId, $action), $signature)) {
            return $this->json(['error' => ['message' => 'Invalid or expired task action.']], Response::HTTP_FORBIDDEN);
        }

        $run = $entityManager->getRepository(AgentRun::class)->find($runId);
        if (!$run instanceof AgentRun) {
            return $this->json(['error' => ['message' => 'Task not found.']], Response::HTTP_NOT_FOUND);
        }
        $userId = is_array($payload) && is_string($payload['user_id'] ?? null) ? $payload['user_id'] : '';
        $userName = is_array($payload) && is_string($payload['user_name'] ?? null) ? $payload['user_name'] : '';
        if ('cancel' !== $action && null !== $run->requesterId() && '' !== $userId && $run->requesterId() !== $userId) {
            return $this->json(['error' => ['message' => 'Only the task requester can control this task.']], Response::HTTP_FORBIDDEN);
        }

        $ephemeral = match ($action) {
            'details' => $this->technicalLog($run),
            'cancel' => $this->cancel($run, '' !== $userName ? $userName : $userId),
            'retry' => $this->retry($run, 'Retry the task from the last useful completed stage.', $messageBus),
            'resume' => $this->retry($run, 'Resume the stopped task from the preserved workspace.', $messageBus),
            'discard' => $this->discard($run, $filesystem),
            default => null,
        };
        if (null === $ephemeral) {
            return $this->json(['error' => ['message' => 'This action is not available for the current task state.']], Response::HTTP_CONFLICT);
        }

        $eventType = match ($action) {
            'cancel' => RunEvent::TYPE_CANCELLATION_REQUESTED,
            'retry', 'resume' => RunEvent::TYPE_RETRY_REQUESTED,
            'discard' => RunEvent::TYPE_WORKSPACE_DISCARDED,
            default => null,
        };
        if (null !== $eventType) {
            $runEventRecorder->record($run, $eventType, $ephemeral, [
                'source' => 'mattermost_button',
                'user_id' => $userId,
                'user_name' => $userName,
            ]);
        }

        $entityManager->flush();
        $card = $renderer->render($run);

        return $this->json([
            'update' => ['message' => $card->message, 'props' => $card->props],
            'ephemeral_text' => $ephemeral,
        ]);
    }

    private function cancel(AgentRun $run, string $stoppedByName): ?string
    {
        if (!$run->isActive()) {
            return null;
        }
        $run->requestCancellation($stoppedByName);

        return AgentRun::STATUS_INTERRUPTED === $run->status()
            ? 'Stopped the task. The workspace will be preserved for 24 hours.'
            : 'Stopping after the current command. The workspace will be preserved for 24 hours.';
    }

    private function retry(AgentRun $run, string $instruction, MessageBusInterface $messageBus): ?string
    {
        if (!$run->isTerminal()) {
            return null;
        }
        $run->prepareRetry($instruction);
        $runId = $run->id();
        if (null !== $runId) {
            $messageBus->dispatch(new RunAgentRunMessage($runId));
        }

        return 'Queued the task in its preserved workspace.';
    }

    private function discard(AgentRun $run, Filesystem $filesystem): ?string
    {
        if (AgentRun::STATUS_INTERRUPTED !== $run->status() || null === $run->workspacePath()) {
            return null;
        }
        $filesystem->remove($run->workspacePath());
        $run->markWorkspaceCleaned();

        return 'Discarded the preserved workspace.';
    }

    private function technicalLog(AgentRun $run): string
    {
        $log = u($run->logSummary() ?? 'No technical log has been recorded yet.')->trim()->toString();

        return "Technical log for task #{$run->id()}:\n```\n".u($log)->slice(0, 3200)."\n```";
    }
}
