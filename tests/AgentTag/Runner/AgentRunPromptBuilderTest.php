<?php

namespace App\Tests\AgentTag\Runner;

use App\AgentTag\Runner\AgentRunPromptBuilder;
use App\AgentTag\Runner\TaskModelSelection;
use App\Entity\AgentRun;
use App\Entity\ChatSession;
use PHPUnit\Framework\TestCase;

final class AgentRunPromptBuilderTest extends TestCase
{
    public function testItRequiresTheSelectedModelToWorkDirectly(): void
    {
        $run = $this->taskRun(TaskModelSelection::mainLuna('Straightforward wording change with known context.'));

        $prompt = (new AgentRunPromptBuilder())->build($run);

        self::assertStringContainsString('Complete the task directly in this session.', $prompt);
        self::assertStringContainsString('Do not delegate it to a subagent.', $prompt);
        self::assertStringNotContainsString('Model routing decision', $prompt);
    }

    public function testItRestrictsResponsesToFrenchOrEnglishWithFrenchAsTheDefault(): void
    {
        $prompt = (new AgentRunPromptBuilder())->build($this->taskRun(TaskModelSelection::mainLuna()));

        self::assertStringContainsString('Answer only in French or English.', $prompt);
        self::assertStringContainsString('Answer in English only when the latest user message is confidently determined to be English.', $prompt);
        self::assertStringContainsString('mixed, ambiguous, language-neutral, written in another language, or its language is uncertain', $prompt);
    }

    public function testItDoesNotIncludeModelRoutingOrSubagentInstructions(): void
    {
        $run = $this->taskRun(TaskModelSelection::fromRoute('sol-xhigh', 'Cross-system feature requiring architecture work.'));

        $prompt = (new AgentRunPromptBuilder())->build($run);

        self::assertStringContainsString('Do not delegate it to a subagent.', $prompt);
        self::assertStringNotContainsString('sol-xhigh', $prompt);
        self::assertStringNotContainsString('spawn', $prompt);
    }

    public function testItMarksTheCurrentRequestAsAuthoritativeAfterTheThreadContext(): void
    {
        $run = new AgentRun(
            new ChatSession('mattermost:t:c:p', 't', 'c', 'p', new \DateTimeImmutable()),
            AgentRun::STATUS_ACCEPTED,
            new \DateTimeImmutable(),
            inputSummary: '@agent explain the failing import fields',
            contextSnapshot: "Thread messages:\n- user: 10h?",
        );

        $prompt = (new AgentRunPromptBuilder())->build($run);

        self::assertStringContainsString(
            "Thread messages:\n- user: 10h?\n\nCurrent user request (authoritative; answer this request):\n@agent explain the failing import fields",
            $prompt,
        );
    }

    public function testAResumedTaskKeepsTheSameSessionContext(): void
    {
        $run = $this->taskRun(TaskModelSelection::fromRoute('sol-medium', 'Read-heavy synthesis across several documents.'));
        $run->recordCodexThread('thread-id');

        $prompt = (new AgentRunPromptBuilder())->build($run);

        self::assertStringContainsString('This is a resumed stage of the same task.', $prompt);
        self::assertStringContainsString('Do not delegate it to a subagent.', $prompt);
    }

    private function taskRun(?TaskModelSelection $selection): AgentRun
    {
        $session = new ChatSession('mattermost:t:c:p', 't', 'c', 'p', new \DateTimeImmutable());
        $run = new AgentRun($session, AgentRun::STATUS_ACCEPTED, new \DateTimeImmutable(), contextSnapshot: 'Task context.');
        $run->initializeTask('Handle task', 'Workspace ready', 'thomas', new \DateTimeImmutable('+1 day'), 2, 60, 'milestones', $selection);

        return $run;
    }
}
