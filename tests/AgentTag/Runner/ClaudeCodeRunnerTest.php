<?php

namespace App\Tests\AgentTag\Runner;

use App\AgentTag\Runner\AgentRunnerInput;
use App\AgentTag\Runner\AgentRunnerProgress;
use App\AgentTag\Runner\ClaudeCodeRunner;
use App\AgentTag\Runner\ProcessFactory;
use App\AgentTag\Runner\RunnerProcess;
use PHPUnit\Framework\TestCase;

use function Symfony\Component\String\u;

final class ClaudeCodeRunnerTest extends TestCase
{
    private string $workingDirectory;

    private string $artifactsDirectory;

    #[\Override]
    protected function setUp(): void
    {
        $this->workingDirectory = sys_get_temp_dir().'/agentag-claude-runner-'.bin2hex(random_bytes(6));
        $this->artifactsDirectory = $this->workingDirectory.'/artifacts';
        mkdir($this->workingDirectory);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeDirectory($this->workingDirectory);
    }

    public function testItRunsClaudeCodeHeadlessWithTheSelectedModelAndEffort(): void
    {
        $factory = new ClaudeProcessFactory();
        $started = [];

        $result = (new ClaudeCodeRunner($factory, '/opt/claude'))->run(new AgentRunnerInput(
            'Implement the task.',
            $this->workingDirectory,
            $this->artifactsDirectory,
            ['HOME' => '/root'],
            300,
            'codex-full-access',
            sessionStartedCallback: static function (string $sessionId) use (&$started): void { $started[] = $sessionId; },
            model: 'claude-opus-5-5',
            reasoningEffort: 'medium',
            runner: 'claude',
        ));

        self::assertSame([
            '/opt/claude',
            '--print',
            '--output-format', 'stream-json',
            '--verbose',
            '--dangerously-skip-permissions',
            '--model', 'claude-opus-5-5',
            '--effort', 'medium',
        ], $factory->command);
        self::assertSame($this->workingDirectory, $factory->workingDirectory);
        self::assertSame(['IS_SANDBOX' => '1', 'HOME' => '/root'], $factory->environment);
        self::assertSame(300, $factory->timeoutSeconds);
        self::assertStringStartsWith("Implement the task.\n\nMattermost task input files:\n", $factory->input);
        self::assertStringContainsString($this->artifactsDirectory.'/reply-files', $factory->input);
        self::assertDirectoryExists($this->artifactsDirectory.'/input-files');
        self::assertTrue($result->successful());
        self::assertSame('Final answer from Claude.', $result->finalMessage());
        self::assertSame('claude-session', $result->sessionId());
        self::assertSame(['claude-session'], $started);
        $tokenUsage = $result->tokenUsage();
        self::assertNotNull($tokenUsage);
        self::assertSame(4 + 100 + 1000, $tokenUsage->inputTokens());
        self::assertSame(20, $tokenUsage->outputTokens());
    }

    public function testItResumesTheSameClaudeSession(): void
    {
        $factory = new ClaudeProcessFactory();

        (new ClaudeCodeRunner($factory))->run(new AgentRunnerInput(
            'Continue the task.',
            $this->workingDirectory,
            $this->artifactsDirectory,
            [],
            300,
            'codex-full-access',
            resumeSessionId: 'prior-session',
            model: 'claude-opus-5-5',
            reasoningEffort: 'high',
            runner: 'claude',
        ));

        self::assertSame(['--resume', 'prior-session'], array_slice($factory->command, -2));
        self::assertSame('claude', $factory->command[0]);
    }

    public function testItExposesTheWorkspaceInstructionsAndSkillsToClaudeCode(): void
    {
        file_put_contents($this->workingDirectory.'/AGENTS.md', "# Agent rules\n");
        mkdir($this->workingDirectory.'/.agents/skills/implement-issue', 0777, true);

        (new ClaudeCodeRunner(new ClaudeProcessFactory()))->run($this->input());

        self::assertSame("@AGENTS.md\n", file_get_contents($this->workingDirectory.'/CLAUDE.md'));
        self::assertSame('../.agents/skills', readlink($this->workingDirectory.'/.claude/skills'));
        self::assertDirectoryExists($this->workingDirectory.'/.claude/skills/implement-issue');
    }

    public function testItKeepsAnExistingClaudeMd(): void
    {
        file_put_contents($this->workingDirectory.'/AGENTS.md', "# Agent rules\n");
        file_put_contents($this->workingDirectory.'/CLAUDE.md', "Custom\n");

        (new ClaudeCodeRunner(new ClaudeProcessFactory()))->run($this->input());

        self::assertSame("Custom\n", file_get_contents($this->workingDirectory.'/CLAUDE.md'));
    }

    public function testItMirrorsAssistantTextAndMcpFailuresAsProgress(): void
    {
        $factory = new ClaudeProcessFactory();
        $progressSink = new TraceableProgressSink();

        (new ClaudeCodeRunner($factory))->run(new AgentRunnerInput(
            'Implement the task.',
            $this->workingDirectory,
            $this->artifactsDirectory,
            [],
            300,
            'codex-full-access',
            $progressSink,
            model: 'claude-opus-5-5',
            reasoningEffort: 'medium',
            runner: 'claude',
        ));

        $types = array_map(static fn (AgentRunnerProgress $progress): string => $progress->type(), $progressSink->progress);
        self::assertSame(['mcp_startup_failed', 'agent_message', 'agent_message'], $types);
        self::assertSame(['server' => 'linear'], $progressSink->progress[0]->context());
        self::assertSame('The MCP server "linear" needs authentication; the task will continue without its tools.', $progressSink->progress[0]->message());
        self::assertSame('I am checking the repository.', $progressSink->progress[1]->message());
    }

    public function testItTreatsAnErrorResultAsAFailedRun(): void
    {
        $factory = new ClaudeProcessFactory();
        $factory->stdout = u("\n")->join([
            '{"type":"system","subtype":"init","session_id":"claude-session","mcp_servers":[]}',
            '{"type":"result","subtype":"error_during_execution","is_error":true,"result":"API Error: overloaded","session_id":"claude-session","usage":{"input_tokens":1,"output_tokens":0}}',
        ])->toString()."\n";

        $result = (new ClaudeCodeRunner($factory))->run($this->input());

        self::assertFalse($result->successful());
        self::assertSame('API Error: overloaded', $result->finalMessage());
    }

    public function testItFailsWhenClaudeCodeExitsWithoutAResult(): void
    {
        $factory = new ClaudeProcessFactory();
        $factory->stdout = '';
        $factory->exitCode = 1;
        $factory->stderr = 'Invalid API key';

        $result = (new ClaudeCodeRunner($factory))->run($this->input());

        self::assertSame(1, $result->exitCode());
        self::assertSame('Run failed before Claude Code produced a final message: Invalid API key', $result->finalMessage());
    }

    public function testItStopsClaudeCodeWhenInterruptionIsRequested(): void
    {
        $factory = new ClaudeProcessFactory();

        $result = (new ClaudeCodeRunner($factory))->run(new AgentRunnerInput(
            'Implement the task.',
            $this->workingDirectory,
            $this->artifactsDirectory,
            [],
            300,
            'codex-full-access',
            interruptionChecker: static fn (): bool => true,
            model: 'claude-opus-5-5',
            reasoningEffort: 'medium',
            runner: 'claude',
        ));

        self::assertSame(130, $result->exitCode());
        self::assertSame('Run interrupted by a newer message in this thread.', $result->finalMessage());
        self::assertNotNull($factory->process);
        self::assertTrue($factory->process->stopped);
    }

    public function testItExtractsAWaitDirective(): void
    {
        $factory = new ClaudeProcessFactory();
        $factory->stdout = u("\n")->join([
            '{"type":"system","subtype":"init","session_id":"claude-session","mcp_servers":[]}',
            '{"type":"result","subtype":"success","is_error":false,"result":'.json_encode("PR #184 is open and CI is running.\n\n<!-- agentag:{\"action\":\"wait\",\"seconds\":300,\"reason\":\"Waiting for CI\"} -->").',"session_id":"claude-session"}',
        ])->toString()."\n";

        $result = (new ClaudeCodeRunner($factory))->run($this->input());

        self::assertSame('PR #184 is open and CI is running.', $result->finalMessage());
        self::assertNotNull($result->continuation());
        self::assertSame(300, $result->continuation()->delaySeconds());
    }

    public function testItRejectsReasoningEffortsClaudeCodeDoesNotSupport(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ClaudeCodeRunner(new ClaudeProcessFactory()))->run(new AgentRunnerInput(
            'Implement the task.',
            $this->workingDirectory,
            $this->artifactsDirectory,
            [],
            300,
            'codex-full-access',
            model: 'claude-opus-5-5',
            reasoningEffort: 'minimal',
            runner: 'claude',
        ));
    }

    private function input(): AgentRunnerInput
    {
        return new AgentRunnerInput(
            'Implement the task.',
            $this->workingDirectory,
            $this->artifactsDirectory,
            [],
            300,
            'codex-full-access',
            model: 'claude-opus-5-5',
            reasoningEffort: 'medium',
            runner: 'claude',
        );
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            return;
        }

        foreach (glob($path.'/{,.}*', \GLOB_BRACE) ?: [] as $entry) {
            if (in_array(basename($entry), ['.', '..'], true)) {
                continue;
            }

            is_dir($entry) && !is_link($entry) ? $this->removeDirectory($entry) : unlink($entry);
        }

        rmdir($path);
    }
}

final class ClaudeProcessFactory implements ProcessFactory
{
    /** @var list<string> */
    public array $command = [];

    public string $workingDirectory = '';

    /** @var array<string, string> */
    public array $environment = [];

    public string $input = '';

    public int $timeoutSeconds = 0;

    public ?ClaudeFakeProcess $process = null;

    public int $exitCode = 0;

    public string $stderr = '';

    public string $stdout;

    public function __construct()
    {
        $this->stdout = u("\n")->join([
            '{"type":"system","subtype":"init","session_id":"claude-session","mcp_servers":[{"name":"linear","status":"needs-auth"},{"name":"github","status":"connected"}]}',
            '{"type":"assistant","message":{"content":[{"type":"text","text":"I am checking the repository."},{"type":"tool_use","name":"Bash","input":{}}]},"parent_tool_use_id":null,"session_id":"claude-session"}',
            '{"type":"assistant","message":{"content":[{"type":"text","text":"Subagent chatter."}]},"parent_tool_use_id":"toolu_1","session_id":"claude-session"}',
            '{"type":"user","message":{"content":[{"type":"tool_result","content":"ok"}]},"session_id":"claude-session"}',
            '{"type":"assistant","message":{"content":[{"type":"text","text":"Final answer from Claude."}]},"parent_tool_use_id":null,"session_id":"claude-session"}',
            '{"type":"result","subtype":"success","is_error":false,"result":"Final answer from Claude.","session_id":"claude-session","usage":{"input_tokens":4,"cache_creation_input_tokens":100,"cache_read_input_tokens":1000,"output_tokens":20}}',
        ])->toString()."\n";
    }

    #[\Override]
    public function create(array $command, string $workingDirectory, array $environment, string $input, int $timeoutSeconds): RunnerProcess
    {
        $this->command = $command;
        $this->workingDirectory = $workingDirectory;
        $this->environment = $environment;
        $this->input = $input;
        $this->timeoutSeconds = $timeoutSeconds;
        $this->process = new ClaudeFakeProcess($this->stdout, $this->stderr, $this->exitCode);

        return $this->process;
    }
}

final class ClaudeFakeProcess implements RunnerProcess
{
    public bool $stopped = false;

    private bool $running = false;

    public function __construct(
        private readonly string $stdout,
        private readonly string $stderr,
        private readonly int $exitCode,
    ) {
    }

    #[\Override]
    public function run(?callable $callback = null): int
    {
        $this->start($callback);

        return $this->wait();
    }

    #[\Override]
    public function start(?callable $callback = null): void
    {
        $this->running = true;
        if (null !== $callback) {
            // Deliver the stream in uneven chunks to exercise line buffering.
            foreach (str_split($this->stdout, 37) as $chunk) {
                $callback('out', $chunk);
            }
            if ('' !== $this->stderr) {
                $callback('err', $this->stderr);
            }
        }
    }

    #[\Override]
    public function wait(?callable $callback = null): int
    {
        $this->running = false;

        return $this->exitCode;
    }

    #[\Override]
    public function isRunning(): bool
    {
        // Report one poll as running so interruption checks are exercised, then finish.
        $running = $this->running;
        $this->running = false;

        return $running;
    }

    #[\Override]
    public function stop(float $timeout = 10.0): int
    {
        $this->stopped = true;
        $this->running = false;

        return 130;
    }

    #[\Override]
    public function exitCode(): int
    {
        return $this->stopped ? 130 : $this->exitCode;
    }

    #[\Override]
    public function output(): string
    {
        return $this->stdout;
    }

    #[\Override]
    public function errorOutput(): string
    {
        return $this->stderr;
    }
}
