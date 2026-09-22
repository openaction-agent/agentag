<?php

namespace App\AgentTag\Runner;

use function Symfony\Component\String\u;

final readonly class TaskContinuationParser
{
    /** @return array{message: string, continuation: ?TaskContinuation} */
    public function parse(string $message): array
    {
        if (1 !== preg_match('/\s*<!--\s*agentag:(\{.*?\})\s*-->\s*$/s', $message, $matches)) {
            return ['message' => u($message)->trim()->toString(), 'continuation' => null];
        }

        $data = json_decode($matches[1], true);
        $cleanMessage = u($message)->before($matches[0])->trim()->toString();
        if (!is_array($data) || 'wait' !== ($data['action'] ?? null)) {
            return ['message' => $cleanMessage, 'continuation' => null];
        }

        $seconds = $data['seconds'] ?? null;
        $reason = $data['reason'] ?? null;
        if (!is_int($seconds) || !is_string($reason) || '' === u($reason)->trim()->toString()) {
            return ['message' => $cleanMessage, 'continuation' => null];
        }

        return [
            'message' => $cleanMessage,
            'continuation' => new TaskContinuation(max(30, min($seconds, 86400)), u($reason)->trim()->toString()),
        ];
    }
}
