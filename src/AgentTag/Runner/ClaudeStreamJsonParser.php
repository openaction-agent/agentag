<?php

namespace App\AgentTag\Runner;

use function Symfony\Component\String\u;

/**
 * Parses `claude -p --output-format stream-json --verbose` output.
 */
final class ClaudeStreamJsonParser
{
    private const array MCP_FAILURE_STATUSES = ['failed', 'needs-auth'];

    private string $buffer = '';

    private ?string $sessionId = null;

    private ?string $result = null;

    private bool $isError = false;

    private ?string $lastAssistantMessage = null;

    private ?TokenUsage $tokenUsage = null;

    /** @var array<string, true> */
    private array $reportedMcpFailures = [];

    /**
     * @return list<AgentRunnerProgress>
     */
    public function consume(string $chunk): array
    {
        $this->buffer .= $chunk;
        if (!mb_check_encoding($this->buffer, 'UTF-8')) {
            return [];
        }

        $events = [];
        $remaining = u($this->buffer);
        while (null !== $position = $remaining->indexOf("\n")) {
            $events = [...$events, ...$this->progressFromLine($remaining->slice(0, $position)->toString())];
            $remaining = $remaining->slice($position + 1);
        }
        $this->buffer = $remaining->toString();

        return $events;
    }

    /**
     * @return list<AgentRunnerProgress>
     */
    public function flush(): array
    {
        $line = $this->buffer;
        $this->buffer = '';

        return $this->progressFromLine($line);
    }

    public function sessionId(): ?string
    {
        return $this->sessionId;
    }

    public function result(): ?string
    {
        return $this->result;
    }

    public function isError(): bool
    {
        return $this->isError;
    }

    public function lastAssistantMessage(): ?string
    {
        return $this->lastAssistantMessage;
    }

    public function tokenUsage(): ?TokenUsage
    {
        return $this->tokenUsage;
    }

    /**
     * @return list<AgentRunnerProgress>
     */
    private function progressFromLine(string $line): array
    {
        $line = u($line)->trim()->toString();
        if ('' === $line) {
            return [];
        }

        try {
            $data = json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($data)) {
            return [];
        }

        if (is_string($data['session_id'] ?? null) && '' !== $data['session_id']) {
            $this->sessionId = $data['session_id'];
        }

        return match ($data['type'] ?? null) {
            'system' => 'init' === ($data['subtype'] ?? null) ? $this->mcpFailures($data) : [],
            'assistant' => $this->assistantProgress($data),
            'result' => $this->recordResult($data),
            default => [],
        };
    }

    /**
     * @param array<mixed, mixed> $data
     *
     * @return list<AgentRunnerProgress>
     */
    private function mcpFailures(array $data): array
    {
        $servers = $data['mcp_servers'] ?? null;
        if (!is_array($servers)) {
            return [];
        }

        $events = [];
        foreach ($servers as $server) {
            if (!is_array($server) || !is_string($server['name'] ?? null) || !is_string($server['status'] ?? null)) {
                continue;
            }
            $name = $server['name'];
            $status = $server['status'];
            if (!in_array($status, self::MCP_FAILURE_STATUSES, true) || isset($this->reportedMcpFailures[$name])) {
                continue;
            }
            $this->reportedMcpFailures[$name] = true;
            $events[] = new AgentRunnerProgress(
                'mcp_startup_failed',
                'needs-auth' === $status
                    ? sprintf('The MCP server "%s" needs authentication; the task will continue without its tools.', $name)
                    : sprintf('The MCP server "%s" did not load; the task will continue without its tools.', $name),
                ['server' => $name],
            );
        }

        return $events;
    }

    /**
     * @param array<mixed, mixed> $data
     *
     * @return list<AgentRunnerProgress>
     */
    private function assistantProgress(array $data): array
    {
        // Messages from subagents carry the parent tool call; only the main agent's text is progress.
        if (null !== ($data['parent_tool_use_id'] ?? null)) {
            return [];
        }
        $content = is_array($data['message'] ?? null) ? ($data['message']['content'] ?? null) : null;
        if (!is_array($content)) {
            return [];
        }

        $texts = [];
        foreach ($content as $block) {
            if (is_array($block) && 'text' === ($block['type'] ?? null) && is_string($block['text'] ?? null)) {
                $text = u($block['text'])->trim()->toString();
                if ('' !== $text) {
                    $texts[] = $text;
                }
            }
        }
        if ([] === $texts) {
            return [];
        }

        $message = u("\n\n")->join($texts)->toString();
        $this->lastAssistantMessage = $message;

        return [new AgentRunnerProgress('agent_message', $message)];
    }

    /**
     * @param array<mixed, mixed> $data
     *
     * @return list<AgentRunnerProgress>
     */
    private function recordResult(array $data): array
    {
        $this->isError = true === ($data['is_error'] ?? false) || 'success' !== ($data['subtype'] ?? 'success');
        if (is_string($data['result'] ?? null)) {
            $this->result = u($data['result'])->trim()->toString();
        }

        $usage = $data['usage'] ?? null;
        if (is_array($usage) && is_int($usage['input_tokens'] ?? null) && is_int($usage['output_tokens'] ?? null)) {
            $cacheCreation = is_int($usage['cache_creation_input_tokens'] ?? null) ? $usage['cache_creation_input_tokens'] : 0;
            $cacheRead = is_int($usage['cache_read_input_tokens'] ?? null) ? $usage['cache_read_input_tokens'] : 0;
            $this->tokenUsage = new TokenUsage($usage['input_tokens'] + $cacheCreation + $cacheRead, $usage['output_tokens']);
        }

        return [];
    }
}
