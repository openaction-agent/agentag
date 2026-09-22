<?php

namespace App\AgentTag\Runner;

use function Symfony\Component\String\u;

final readonly class CodexCliRunner implements AgentRunnerInterface
{
    public function __construct(
        private ProcessFactory $processFactory,
        private ReplyArtifactCollector $artifactCollector = new ReplyArtifactCollector(),
    ) {
    }

    #[\Override]
    public function run(AgentRunnerInput $input): AgentRunnerResult
    {
        if ('' === u($input->model())->trim()->toString()) {
            throw new \InvalidArgumentException('Codex task model must not be blank.');
        }
        if (!in_array($input->reasoningEffort(), ['none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max', 'ultra'], true)) {
            throw new \InvalidArgumentException('Codex task reasoning effort is invalid.');
        }
        $fileProtocol = RunnerFileProtocol::prepare($input->artifactsDirectory());

        $lastMessagePath = $input->artifactsDirectory().'/codex-last-message.txt';
        $command = null === $input->resumeSessionId()
            ? [
                'codex', 'exec',
                '--dangerously-bypass-approvals-and-sandbox',
                '--skip-git-repo-check',
                '--json',
                '--model', $input->model(),
                '-c', sprintf('model_reasoning_effort="%s"', $input->reasoningEffort()),
                '--cd', $input->workingDirectory(),
                '--output-last-message', $lastMessagePath,
                '-',
            ]
            : [
                'codex', 'exec', 'resume',
                '--dangerously-bypass-approvals-and-sandbox',
                '--skip-git-repo-check',
                '--json',
                '--model', $input->model(),
                '-c', sprintf('model_reasoning_effort="%s"', $input->reasoningEffort()),
                '--output-last-message', $lastMessagePath,
                $input->resumeSessionId(),
                '-',
            ];

        $process = $this->processFactory->create(
            $command,
            $input->workingDirectory(),
            $input->environment(),
            $fileProtocol->appendTo($input->prompt()),
            $input->timeoutSeconds(),
        );
        $parser = new CodexJsonEventParser();
        $interrupted = false;
        $reportedSessionId = $input->resumeSessionId();
        $callback = function (string $type, string $buffer) use ($input, $parser, &$reportedSessionId): void {
            $progressEvents = match (true) {
                'out' === $type || u($type)->endsWith('OUT') => $parser->consume($buffer),
                'err' === $type || u($type)->endsWith('ERR') => $parser->consumeStderr($buffer),
                default => [],
            };
            foreach ($progressEvents as $progress) {
                $input->progressSink()?->onProgress($progress);
            }
            if (null !== $parser->threadId() && $parser->threadId() !== $reportedSessionId) {
                $reportedSessionId = $parser->threadId();
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
        $exitCode = $process->exitCode();
        if ($interrupted) {
            return new AgentRunnerResult(
                130,
                'Run interrupted by a newer message in this thread.',
                $stdout,
                $stderr,
                [],
                $this->tokenUsageFromOutput($stdout),
                $parser->threadId() ?? $input->resumeSessionId(),
            );
        }

        $finalMessage = $this->finalMessage($lastMessagePath, $stdout, $exitCode, $parser);
        $parsed = (new TaskContinuationParser())->parse($finalMessage);

        return new AgentRunnerResult(
            $exitCode,
            $parsed['message'],
            $stdout,
            $stderr,
            $this->artifactCollector->collect($input->artifactsDirectory()),
            $this->tokenUsageFromOutput($stdout),
            $parser->threadId() ?? $input->resumeSessionId(),
            $parsed['continuation'],
        );
    }

    private function finalMessage(string $lastMessagePath, string $stdout, int $exitCode, CodexJsonEventParser $parser): string
    {
        if (is_file($lastMessagePath)) {
            $message = u((string) file_get_contents($lastMessagePath))->trim()->toString();
            if ('' !== $message) {
                return $message;
            }
        }

        $message = $parser->lastAgentMessageFromOutput($stdout);
        if (null !== $message) {
            return $message;
        }

        $stdout = u($stdout)->trim()->toString();
        if ('' === $stdout) {
            return 0 === $exitCode
                ? 'Run completed, but Codex did not provide a final message.'
                : 'Run failed before Codex produced a final message.';
        }

        if ($this->looksLikeJsonEventOutput($stdout)) {
            return 0 === $exitCode
                ? 'Run completed, but Codex did not provide a final message.'
                : 'Run failed before Codex produced a final message.';
        }

        return $stdout;
    }

    private function looksLikeJsonEventOutput(string $stdout): bool
    {
        foreach (u($stdout)->split("\n") as $line) {
            $line = $line->trim()->toString();
            if ('' === $line) {
                continue;
            }

            try {
                $data = json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return false;
            }

            if (!is_array($data) || (!isset($data['type']) && !isset($data['item']) && !isset($data['usage']) && !isset($data['token_usage']))) {
                return false;
            }
        }

        return true;
    }

    private function tokenUsageFromOutput(string $stdout): ?TokenUsage
    {
        foreach (u($stdout)->split("\n") as $line) {
            $data = json_decode($line->toString(), true);
            if (!is_array($data)) {
                continue;
            }

            $usage = $data['token_usage'] ?? $data['usage'] ?? null;
            if (!is_array($usage)) {
                continue;
            }

            $inputTokens = $usage['input_tokens'] ?? $usage['prompt_tokens'] ?? null;
            $outputTokens = $usage['output_tokens'] ?? $usage['completion_tokens'] ?? null;
            if (is_int($inputTokens) && is_int($outputTokens)) {
                return new TokenUsage($inputTokens, $outputTokens);
            }
        }

        return null;
    }
}
