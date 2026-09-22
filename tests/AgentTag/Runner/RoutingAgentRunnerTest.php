<?php

namespace App\Tests\AgentTag\Runner;

use App\AgentTag\Runner\AgentRunnerInput;
use App\AgentTag\Runner\ClaudeCodeRunner;
use App\AgentTag\Runner\CodexCliRunner;
use App\AgentTag\Runner\RoutingAgentRunner;
use App\AgentTag\Runner\TaskModelSelection;
use PHPUnit\Framework\TestCase;

final class RoutingAgentRunnerTest extends TestCase
{
    private string $workingDirectory;

    #[\Override]
    protected function setUp(): void
    {
        $this->workingDirectory = sys_get_temp_dir().'/agentag-routing-runner-'.bin2hex(random_bytes(6));
        mkdir($this->workingDirectory);
    }

    #[\Override]
    protected function tearDown(): void
    {
        (new \Symfony\Component\Filesystem\Filesystem())->remove($this->workingDirectory);
    }

    public function testItRunsClaudeRoutesWithClaudeCode(): void
    {
        $codexFactory = new TraceableProcessFactory();
        $claudeFactory = new ClaudeProcessFactory();
        $runner = new RoutingAgentRunner(new CodexCliRunner($codexFactory), new ClaudeCodeRunner($claudeFactory));
        $selection = TaskModelSelection::fromRoute('opus-5-5-medium', 'Implementation.');
        self::assertNotNull($selection);

        $runner->run($this->input($selection));

        self::assertSame('claude', $claudeFactory->command[0] ?? null);
        self::assertSame([], $codexFactory->command);
    }

    public function testItRunsCodexRoutesWithCodex(): void
    {
        $codexFactory = new TraceableProcessFactory();
        $claudeFactory = new ClaudeProcessFactory();
        $runner = new RoutingAgentRunner(new CodexCliRunner($codexFactory), new ClaudeCodeRunner($claudeFactory));

        $runner->run($this->input(TaskModelSelection::fallback()));

        self::assertSame('codex', $codexFactory->command[0] ?? null);
        self::assertContains('gpt-6-sol', $codexFactory->command);
        self::assertSame([], $claudeFactory->command);
    }

    public function testItRejectsUnknownRunners(): void
    {
        $runner = new RoutingAgentRunner(new CodexCliRunner(new TraceableProcessFactory()), new ClaudeCodeRunner(new ClaudeProcessFactory()));

        $this->expectException(\InvalidArgumentException::class);

        $runner->run(new AgentRunnerInput('Task.', $this->workingDirectory, $this->workingDirectory.'/artifacts', [], 60, 'codex-full-access', runner: 'other'));
    }

    private function input(TaskModelSelection $selection): AgentRunnerInput
    {
        return new AgentRunnerInput(
            'Task.',
            $this->workingDirectory,
            $this->workingDirectory.'/artifacts',
            [],
            60,
            'codex-full-access',
            model: $selection->model,
            reasoningEffort: $selection->effort,
            runner: $selection->runner,
        );
    }
}
