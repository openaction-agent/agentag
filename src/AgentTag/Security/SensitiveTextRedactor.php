<?php

namespace App\AgentTag\Security;

use function Symfony\Component\String\u;

final readonly class SensitiveTextRedactor
{
    private const SECRET_ASSIGNMENT_PATTERN = '/(?P<key_quote>["\']?)\b(?P<name>authorization|api[_-]?key|access[_-]?token|refresh[_-]?token|token|secret|password|passwd|pwd)\b(?P=key_quote)(?P<spacing>\s*[:=]\s*)(?P<value>"[^"]*"|\'[^\']*\'|[^\s,;}]+)/i';

    /**
     * @var array<string, string>
     */
    private const SECRET_PATTERNS = [
        '/\bBearer\s+[A-Za-z0-9._~+\/=-]{8,}\b/i' => 'Bearer [REDACTED]',
        '/\bgh[pousr]_[A-Za-z0-9_]{20,}\b/' => '[REDACTED_GITHUB_TOKEN]',
        '/\bAKIA[0-9A-Z]{16}\b/' => '[REDACTED_AWS_ACCESS_KEY]',
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s' => '[REDACTED_PRIVATE_KEY]',
    ];

    /**
     * @var list<string>
     */
    private array $customPatterns;

    public function __construct(string $customPatterns = '')
    {
        $this->customPatterns = $this->parseCustomPatterns($customPatterns);
    }

    public function redact(string $text): string
    {
        $text = $this->redactKnownPatterns($text);
        $text = $this->redactCustomPatterns($text);

        $redacted = u($text)->replaceMatches(
            self::SECRET_ASSIGNMENT_PATTERN,
            static fn (array $matches): string => sprintf(
                '%s%s%s%s%s',
                self::stringMatch($matches, 'key_quote'),
                self::stringMatch($matches, 'name'),
                self::stringMatch($matches, 'key_quote'),
                self::stringMatch($matches, 'spacing'),
                self::redactedAssignmentValue(self::stringMatch($matches, 'value')),
            ),
        )->toString();

        $text = $redacted;

        return $this->redactCustomPatterns($this->redactKnownPatterns($text));
    }

    private function redactKnownPatterns(string $text): string
    {
        foreach (self::SECRET_PATTERNS as $pattern => $replacement) {
            $text = u($text)->replaceMatches($pattern, $replacement)->toString();
        }

        return $text;
    }

    private function redactCustomPatterns(string $text): string
    {
        foreach ($this->customPatterns as $pattern) {
            $text = u($text)->replaceMatches($pattern, '[REDACTED]')->toString();
        }

        return $text;
    }

    /**
     * @return list<string>
     */
    private function parseCustomPatterns(string $customPatterns): array
    {
        if ('' === u($customPatterns)->trim()->toString()) {
            return [];
        }

        $patterns = [];
        foreach ($this->patternStrings($customPatterns) as $pattern) {
            $pattern = u($pattern)->trim()->toString();
            if ('' === $pattern) {
                continue;
            }

            $this->assertValidPattern($pattern);
            $patterns[] = $pattern;
        }

        return $patterns;
    }

    /**
     * @return list<string>
     */
    private function patternStrings(string $customPatterns): array
    {
        $customPatterns = u($customPatterns)->trim()->toString();
        if (u($customPatterns)->startsWith('[')) {
            $decoded = json_decode($customPatterns, true);
            if (!is_array($decoded)) {
                throw new \InvalidArgumentException('AgentTag redaction patterns JSON must decode to a list of strings.');
            }

            $patterns = [];
            foreach ($decoded as $pattern) {
                if (!is_string($pattern)) {
                    throw new \InvalidArgumentException('AgentTag redaction patterns JSON must contain only strings.');
                }

                $patterns[] = $pattern;
            }

            return $patterns;
        }

        $patterns = [];
        foreach (u($customPatterns)->split('/\R+/', flags: 0) as $pattern) {
            $patterns[] = $pattern->toString();
        }

        return $patterns;
    }

    private function assertValidPattern(string $pattern): void
    {
        set_error_handler(static fn (): bool => true);
        try {
            $isValid = false !== preg_match($pattern, '');
        } finally {
            restore_error_handler();
        }

        if (!$isValid) {
            throw new \InvalidArgumentException(sprintf('Invalid AgentTag redaction pattern "%s".', $pattern));
        }
    }

    private static function redactedAssignmentValue(string $value): string
    {
        if (u($value)->startsWith('"') && u($value)->endsWith('"')) {
            return '"[REDACTED]"';
        }

        if (u($value)->startsWith("'") && u($value)->endsWith("'")) {
            return "'[REDACTED]'";
        }

        return '[REDACTED]';
    }

    /**
     * @param array<int|string, mixed> $matches
     */
    private static function stringMatch(array $matches, string $key): string
    {
        $value = $matches[$key] ?? '';

        return is_string($value) ? $value : '';
    }
}
