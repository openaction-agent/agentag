<?php

namespace App\AgentTag\Runner;

use function Symfony\Component\String\u;

final readonly class ClaudeCodeRunner implements AgentRunnerInterface
{
    private const array REASONING_EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    public function __construct(
        private ProcessFactory $processFactory,
        private string $binary = 'claude',
        private ReplyArtifactCollector $artifactCollector = new ReplyArtifactCollector(),
    ) {
    }

    #[\Override]
    public function run(AgentRunnerInput $input): AgentRunnerResult
    {
        if ('' === u($input->model())->trim()->toString()) {
            throw new \InvalidArgumentException('Claude Code task model must not be blank.');
        }
        if (!in_array($input->reasoningEffort(), self::REASONING_EFFORTS, true)) {
            throw new \InvalidArgumentException('Claude Code task reasoning effort is invalid.');
        }
        $fileProtocol = RunnerFileProtocol::prepare($input->artifactsDirectory());
        $this->exposeWorkspaceInstructions($input->workingDirectory());

        $command = [
            $this->binary,
            '--print',
            '--output-format', 'stream-json',
            '--verbose',
            '--dangerously-skip-permissions',
            '--model', $input->model(),
            '--effort', $input->reasoningEffort(),
        ];
        if (null !== $input->resumeSessionId()) {
            $command = [...$command, '--resume', $input->resumeSessionId()];
        }

        // Workers run as root, where Claude Code only allows skipping permission
        // prompts inside a declared sandbox; this matches the Codex runner's
        // --dangerously-bypass-approvals-and-sandbox mode.
        $process = $this->processFactory->create(
            $command,
            $input->workingDirectory(),
            ['IS_SANDBOX' => '1', ...$input->environment()],
            $fileProtocol->appendTo($input->prompt()),
            $input->timeoutSeconds(),
        );
        $parser = new ClaudeStreamJsonParser();
        $interrupted = false;
        $reportedSessionId = $input->resumeSessionId();
        $callback = function (string $type, string $buffer) use ($input, $parser, &$reportedSessionId): void {
            if ('out' !== $type && !u($type)->endsWith('OUT')) {
                return;
            }
            foreach ($parser->consume($buffer) as $progress) {
                $input->progressSink()?->onProgress($progress);
            }
            if (null !== $parser->sessionId() && $parser->sessionId() !== $reportedSessionId) {
                $reportedSessionId = $parser->sessionId();
                $input->sessionStarted($reportedSessionId);
            }
        };

        $input->progressSink()?->onHeartbeat();
        $process->start($callback);
        while ($process->isRunning()) {
            $input->progressSink()?->onHeartbeat();
            if ($input->interruptionRequested()) {
                $interrupted = true;
                $process->stop(5.0);
                break;
            }

            usleep(250000);
        }
        $process->wait($callback);

        foreach ($parser->flush() as $progress) {
            $input->progressSink()?->onProgress($progress);
        }

        $stdout = $process->output();
        $stderr = $process->errorOutput();
        $sessionId = $parser->sessionId() ?? $input->resumeSessionId();
        if ($interrupted) {
            return new AgentRunnerResult(
                130,
                'Run interrupted by a newer message in this thread.',
                $stdout,
                $stderr,
                [],
                $parser->tokenUsage(),
                $sessionId,
            );
        }

        $exitCode = $process->exitCode();
        if (0 === $exitCode && ($parser->isError() || null === $parser->result())) {
            $exitCode = 1;
        }
        $parsed = (new TaskContinuationParser())->parse($this->finalMessage($parser, $stderr, $exitCode));

        return new AgentRunnerResult(
            $exitCode,
            $parsed['message'],
            $stdout,
            $stderr,
            $this->artifactCollector->collect($input->artifactsDirectory()),
            $parser->tokenUsage(),
            $sessionId,
            $parsed['continuation'],
        );
    }

    /**
     * Claude Code reads CLAUDE.md and .claude/skills; the workspace template
     * ships AGENTS.md and .agents/skills for every harness.
     */
    private function exposeWorkspaceInstructions(string $workingDirectory): void
    {
        if (is_file($workingDirectory.'/AGENTS.md') && !file_exists($workingDirectory.'/CLAUDE.md')) {
            file_put_contents($workingDirectory.'/CLAUDE.md', "@AGENTS.md\n");
        }

        $skillsLink = $workingDirectory.'/.claude/skills';
        if (is_dir($workingDirectory.'/.agents/skills') && !file_exists($skillsLink) && !is_link($skillsLink)) {
            if (!is_dir(dirname($skillsLink))) {
                mkdir(dirname($skillsLink), 0777, true);
            }
            symlink('../.agents/skills', $skillsLink);
        }
    }

    private function finalMessage(ClaudeStreamJsonParser $parser, string $stderr, int $exitCode): string
    {
        $message = $parser->result();
        if (null !== $message && '' !== $message) {
            return $message;
        }

        $message = $parser->lastAssistantMessage();
        if (null !== $message) {
            return $message;
        }

        $stderr = u($stderr)->trim()->toString();
        if (0 !== $exitCode && '' !== $stderr) {
            return 'Run failed before Claude Code produced a final message: '.u($stderr)->truncate(500, '…')->toString();
        }

        return 0 === $exitCode
            ? 'Run completed, but Claude Code did not provide a final message.'
            : 'Run failed before Claude Code produced a final message.';
    }
}
