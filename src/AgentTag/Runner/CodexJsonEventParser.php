<?php

namespace App\AgentTag\Runner;

use function Symfony\Component\String\u;

final class CodexJsonEventParser
{
    private string $buffer = '';

    private string $stderrBuffer = '';

    private ?string $threadId = null;

    /** @var array<string, true> */
    private array $reportedMcpFailures = [];

    private ?string $unattributedMcpStartupFailure = null;

    /**
     * @return list<AgentRunnerProgress>
     */
    public function consume(string $chunk): array
    {
        return $this->consumeChunk($chunk, false);
    }

    /**
     * @return list<AgentRunnerProgress>
     */
    public function consumeStderr(string $chunk): array
    {
        return $this->consumeChunk($chunk, true);
    }

    /**
     * @return list<AgentRunnerProgress>
     */
    public function flush(): array
    {
        $events = $this->flushBuffer($this->buffer, false);
        $events = [...$events, ...$this->flushBuffer($this->stderrBuffer, true)];
        if (null !== $this->unattributedMcpStartupFailure && [] === $this->reportedMcpFailures) {
            $events[] = new AgentRunnerProgress(
                'mcp_startup_failed',
                'An MCP server did not load; the task will continue without its tools.',
            );
        }

        return $events;
    }

    /**
     * @return list<AgentRunnerProgress>
     */
    private function consumeChunk(string $chunk, bool $fromStderr): array
    {
        if ($fromStderr) {
            $this->stderrBuffer .= $chunk;
            $buffer = &$this->stderrBuffer;
        } else {
            $this->buffer .= $chunk;
            $buffer = &$this->buffer;
        }
        $events = [];

        if (!mb_check_encoding($buffer, 'UTF-8')) {
            return $events;
        }

        $remaining = u($buffer);
        while (null !== $position = $remaining->indexOf("\n")) {
            $line = $remaining->slice(0, $position)->toString();
            $remaining = $remaining->slice($position + 1);
            $event = $this->progressFromLine($line, $fromStderr);
            if (null !== $event) {
                $events[] = $event;
            }
        }
        $buffer = $remaining->toString();

        return $events;
    }

    /**
     * @return list<AgentRunnerProgress>
     */
    private function flushBuffer(string &$buffer, bool $fromStderr): array
    {
        $line = $buffer;
        $buffer = '';
        $event = $this->progressFromLine($line, $fromStderr);

        return null === $event ? [] : [$event];
    }

    public function lastAgentMessageFromOutput(string $output): ?string
    {
        $lastMessage = null;
        foreach (u($output)->split("\n") as $line) {
            $line = $line->trim()->toString();
            if ('' === $line) {
                continue;
            }

            try {
                $data = json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            if (!is_array($data)) {
                continue;
            }
            if ('thread.started' === ($data['type'] ?? null) && is_string($data['thread_id'] ?? null)) {
                $this->threadId = $data['thread_id'];
            }

            $message = $this->agentMessageFromData($data);
            if (null !== $message) {
                $lastMessage = $message;
            }
        }

        return $lastMessage;
    }

    public function threadId(): ?string
    {
        return $this->threadId;
    }

    private function progressFromLine(string $line, bool $fromStderr): ?AgentRunnerProgress
    {
        $line = u($line)->trim()->toString();
        if ('' === $line) {
            return null;
        }

        try {
            $data = json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $fromStderr ? $this->mcpFailureFromText($line) : null;
        }

        if (!is_array($data)) {
            return null;
        }

        $mcpFailure = $this->mcpFailureFromData($data);
        if (null !== $mcpFailure) {
            return $mcpFailure;
        }

        if ('thread.started' === ($data['type'] ?? null) && is_string($data['thread_id'] ?? null)) {
            $this->threadId = $data['thread_id'];

            return null;
        }

        $item = $data['item'] ?? null;
        if (is_array($item)) {
            $itemType = $item['type'] ?? null;
            if (!is_string($itemType) || !in_array($itemType, ['agent_message', 'assistant_message'], true)) {
                return null;
            }
        } else {
            $eventType = $data['type'] ?? null;
            if (!is_string($eventType) || !in_array($eventType, ['agent_message', 'assistant_message'], true)) {
                return null;
            }
        }

        $message = $this->messageFromData($data);
        if (null === $message) {
            return null;
        }

        return new AgentRunnerProgress('agent_message', $message);
    }

    /**
     * @param array<mixed, mixed> $data
     */
    private function mcpFailureFromData(array $data): ?AgentRunnerProgress
    {
        $item = $data['item'] ?? null;
        if (is_array($item) && in_array($item['type'] ?? null, ['agent_message', 'assistant_message'], true)) {
            return $this->mcpFailureFromAgentMessage($this->messageFromData($item));
        }
        if (in_array($data['type'] ?? null, ['agent_message', 'assistant_message'], true)) {
            return $this->mcpFailureFromAgentMessage($this->messageFromData($data));
        }

        if (!$this->isFailureEvent($data) || !$this->hasMcpStartupFailureEvidence($data)) {
            return null;
        }

        return $this->mcpFailureFromText(implode(' ', $this->stringValues($data)), $this->mcpServerFromData($data));
    }

    /**
     * Successful MCP results can contain arbitrary documentation mentioning
     * failures, loading, and MCP tools. Only inspect structured events that
     * actually carry a failure signal.
     *
     * @param array<mixed, mixed> $data
     */
    private function isFailureEvent(array $data): bool
    {
        foreach ([$data, $data['item'] ?? null] as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            foreach (['type', 'status'] as $key) {
                $value = $candidate[$key] ?? null;
                if (is_string($value) && preg_match('/fail|error|timed?[_ -]?out|timeout/i', $value)) {
                    return true;
                }
            }

            $error = $candidate['error'] ?? null;
            if ((is_string($error) && '' !== u($error)->trim()->toString())
                || (is_array($error) && [] !== $error)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ignore arbitrary failed items (notably command executions) whose output
     * happens to contain MCP-related source paths or documentation.
     *
     * @param array<mixed, mixed> $data
     */
    private function hasMcpStartupFailureEvidence(array $data): bool
    {
        foreach ([$data, $data['item'] ?? null] as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $type = $candidate['type'] ?? null;
            if (is_string($type)
                && preg_match('/mcp/i', $type)
                && preg_match('/fail|error|timed?[_ -]?out|timeout/i', $type)) {
                return true;
            }

            foreach (['error', 'message', 'text', 'content', 'summary'] as $key) {
                $diagnostic = $candidate[$key] ?? null;
                if (is_string($diagnostic) && $this->isMcpStartupFailureText($diagnostic)) {
                    return true;
                }
                if (is_array($diagnostic)
                    && $this->isMcpStartupFailureText(implode(' ', $this->stringValues($diagnostic)))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function mcpFailureFromText(string $text, ?string $reportedServer = null): ?AgentRunnerProgress
    {
        if (!$this->isMcpStartupFailureText($text)) {
            return null;
        }

        $server = $reportedServer ?? $this->mcpServerFromText($text);
        if (null === $server) {
            $this->unattributedMcpStartupFailure ??= $text;

            return null;
        }

        return $this->mcpFailureForServer($server);
    }

    private function isMcpStartupFailureText(string $text): bool
    {
        $rmcpTransportFailure = preg_match('/\brmcp::transport::worker\b/i', $text)
            && preg_match('/fatal|transport\s+channel\s+closed|unexpectedserverresponse/i', $text);

        return (bool) ($rmcpTransportFailure
            || (preg_match('/mcp/i', $text)
                && preg_match('/fail(?:ed|ure)?|error|timed?\s*out|timeout|could not|unable to|unavailable|not connected/i', $text)
                && preg_match('/start|initiali[sz]|connect|load|tool(?:s)?\s+list/i', $text)));
    }

    private function mcpFailureFromAgentMessage(?string $message): ?AgentRunnerProgress
    {
        if (null === $message) {
            return null;
        }

        $failure = '(?:n[’\']est\s+(?:pas\s+)?(?:exposé|disponible)|ne\s+répond\s+pas|is(?:\s+not|n[’\']t)?\s+(?:exposed|available)|is\s+unavailable|did\s+not\s+(?:load|start)|failed\s+to\s+(?:load|start))';
        $patterns = [
            '/[`\'\"]([A-Za-z0-9](?:[A-Za-z0-9_.-]{0,126}[A-Za-z0-9])?)[`\'\"]\s+'.$failure.'/iu',
            '/\b(?:mcp\s+(?:server|client)|serveur\s+mcp)\s+[`\'\"]?([A-Za-z0-9](?:[A-Za-z0-9_.-]{0,126}[A-Za-z0-9])?)[`\'\"]?\s+'.$failure.'/iu',
        ];
        foreach ($patterns as $pattern) {
            if (1 === preg_match($pattern, $message, $matches)) {
                return $this->mcpFailureForServer(str_replace('_', '-', $matches[1]));
            }
        }

        return null;
    }

    private function mcpFailureForServer(string $server): ?AgentRunnerProgress
    {
        $key = $server;
        if (isset($this->reportedMcpFailures[$key])) {
            return null;
        }
        $this->reportedMcpFailures[$key] = true;

        return new AgentRunnerProgress(
            'mcp_startup_failed',
            sprintf('The MCP server "%s" did not load; the task will continue without its tools.', $server),
            ['server' => $server],
        );
    }

    private function mcpServerFromText(string $text): ?string
    {
        $patterns = [
            '/\bmcp(?:\s+(?:server|client))?(?:\s+for)?\s+[`\'\"]?([A-Za-z0-9][A-Za-z0-9_.-]{0,127})/i',
            '/\bmcp[_\s-]server(?:[_\s-]name)?\s*[:=]\s*[`\'\"]?([A-Za-z0-9][A-Za-z0-9_.-]{0,127})/i',
            '/\bmcp(?:\s+(?:server|client))?\s+(?:startup\s+)?(?:failed|timed?\s*out|timeout|error)[^A-Za-z0-9_.-]+[`\'\"]([A-Za-z0-9][A-Za-z0-9_.-]{0,127})/i',
        ];
        foreach ($patterns as $pattern) {
            if (1 !== preg_match($pattern, $text, $matches)) {
                continue;
            }

            $server = $matches[1];
            if ($this->isValidMcpServerName($server)
                && !in_array(strtolower($server), ['client', 'error', 'failed', 'server', 'startup', 'timed', 'timeout'], true)) {
                return $server;
            }
        }

        return null;
    }

    /**
     * @param array<mixed, mixed> $data
     */
    private function mcpServerFromData(array $data): ?string
    {
        foreach (['mcp_server', 'mcp_server_name', 'server', 'server_name'] as $key) {
            $server = $data[$key] ?? null;
            if (is_string($server) && $this->isValidMcpServerName($server)) {
                return $server;
            }
        }

        return null;
    }

    private function isValidMcpServerName(string $server): bool
    {
        return 1 === preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9_.-]{0,126}[A-Za-z0-9])?$/', $server);
    }

    /**
     * @param array<mixed, mixed> $data
     *
     * @return list<string>
     */
    private function stringValues(array $data): array
    {
        $values = [];
        foreach ($data as $value) {
            if (is_string($value)) {
                $values[] = $value;
            } elseif (is_array($value)) {
                $values = [...$values, ...$this->stringValues($value)];
            }
        }

        return $values;
    }

    /**
     * @param array<mixed, mixed> $data
     */
    private function messageFromData(array $data): ?string
    {
        foreach (['message', 'text', 'content', 'summary'] as $key) {
            $value = $data[$key] ?? null;
            if (is_scalar($value) && '' !== u((string) $value)->trim()->toString()) {
                return u((string) $value)->trim()->toString();
            }
        }

        $item = $data['item'] ?? null;
        if (is_array($item)) {
            $message = $this->messageFromData($item);
            if (null !== $message) {
                return $message;
            }
        }

        $delta = $data['delta'] ?? null;
        if (is_array($delta)) {
            return $this->messageFromData($delta);
        }

        return null;
    }

    /**
     * @param array<mixed, mixed> $data
     */
    private function agentMessageFromData(array $data): ?string
    {
        $type = $data['type'] ?? null;
        if (is_string($type) && in_array($type, ['agent_message', 'assistant_message'], true)) {
            return $this->messageFromData($data);
        }

        $item = $data['item'] ?? null;
        if (is_array($item)) {
            $itemType = $item['type'] ?? null;
            if (is_string($itemType) && in_array($itemType, ['agent_message', 'assistant_message'], true)) {
                return $this->messageFromData($item);
            }
        }

        return null;
    }
}
