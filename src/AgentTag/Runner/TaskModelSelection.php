<?php

namespace App\AgentTag\Runner;

use function Symfony\Component\String\u;

final readonly class TaskModelSelection
{
    public const string RUNNER_CODEX = 'codex';
    public const string RUNNER_CLAUDE = 'claude';

    public const string FALLBACK_ROUTE = 'gpt-6-sol-high';

    /**
     * Routes offered to the model selector.
     *
     * @var array<string, array{runner: string, model: string, effort: string, display: string}>
     */
    private const array ROUTES = [
        'gpt-6-luna-medium' => ['runner' => self::RUNNER_CODEX, 'model' => 'gpt-6-luna', 'effort' => 'medium', 'display' => 'GPT-6 Luna'],
        'gpt-6-sol-high' => ['runner' => self::RUNNER_CODEX, 'model' => 'gpt-6-sol', 'effort' => 'high', 'display' => 'GPT-6 Sol'],
        'gpt-6-sol-max' => ['runner' => self::RUNNER_CODEX, 'model' => 'gpt-6-sol', 'effort' => 'max', 'display' => 'GPT-6 Sol'],
        'gpt-6-astra-medium' => ['runner' => self::RUNNER_CODEX, 'model' => 'gpt-6-astra', 'effort' => 'medium', 'display' => 'GPT-6 Astra'],
        'gpt-6-astra-max' => ['runner' => self::RUNNER_CODEX, 'model' => 'gpt-6-astra', 'effort' => 'max', 'display' => 'GPT-6 Astra'],
        'opus-5-5-medium' => ['runner' => self::RUNNER_CLAUDE, 'model' => 'claude-opus-5-5', 'effort' => 'medium', 'display' => 'Claude Opus 5.5'],
        'opus-5-5-high' => ['runner' => self::RUNNER_CLAUDE, 'model' => 'claude-opus-5-5', 'effort' => 'high', 'display' => 'Claude Opus 5.5'],
        'opus-5-5-max' => ['runner' => self::RUNNER_CLAUDE, 'model' => 'claude-opus-5-5', 'effort' => 'max', 'display' => 'Claude Opus 5.5'],
    ];

    /**
     * Routes persisted by earlier versions. They stay resolvable so existing
     * threads keep their original model, but are no longer offered.
     *
     * @var array<string, array{runner: string, model: string, effort: string, display: string}>
     */
    private const array LEGACY_ROUTES = [
        'luna-high' => ['runner' => self::RUNNER_CODEX, 'model' => 'gpt-5.6-luna', 'effort' => 'high', 'display' => 'GPT-5.6 Luna'],
        'luna-max' => ['runner' => self::RUNNER_CODEX, 'model' => 'gpt-5.6-luna', 'effort' => 'max', 'display' => 'GPT-5.6 Luna'],
        'luna-medium' => ['runner' => self::RUNNER_CODEX, 'model' => 'gpt-5.6-luna', 'effort' => 'medium', 'display' => 'GPT-5.6 Luna'],
        'luna-xhigh' => ['runner' => self::RUNNER_CODEX, 'model' => 'gpt-5.6-luna', 'effort' => 'xhigh', 'display' => 'GPT-5.6 Luna'],
        'terra-medium' => ['runner' => self::RUNNER_CODEX, 'model' => 'gpt-5.6-terra', 'effort' => 'medium', 'display' => 'GPT-5.6 Terra'],
        'terra-high' => ['runner' => self::RUNNER_CODEX, 'model' => 'gpt-5.6-terra', 'effort' => 'high', 'display' => 'GPT-5.6 Terra'],
        'terra-xhigh' => ['runner' => self::RUNNER_CODEX, 'model' => 'gpt-5.6-terra', 'effort' => 'xhigh', 'display' => 'GPT-5.6 Terra'],
        'terra-max' => ['runner' => self::RUNNER_CODEX, 'model' => 'gpt-5.6-terra', 'effort' => 'max', 'display' => 'GPT-5.6 Terra'],
        'sol-medium' => ['runner' => self::RUNNER_CODEX, 'model' => 'gpt-5.6-sol', 'effort' => 'medium', 'display' => 'GPT-5.6 Sol'],
        'sol-high' => ['runner' => self::RUNNER_CODEX, 'model' => 'gpt-5.6-sol', 'effort' => 'high', 'display' => 'GPT-5.6 Sol'],
        'sol-xhigh' => ['runner' => self::RUNNER_CODEX, 'model' => 'gpt-5.6-sol', 'effort' => 'xhigh', 'display' => 'GPT-5.6 Sol'],
    ];

    private function __construct(
        public string $route,
        public string $runner,
        public string $model,
        public string $effort,
        public string $displayModel,
        public string $reason,
    ) {
    }

    /** @return list<string> */
    public static function selectableRoutes(): array
    {
        return array_keys(self::ROUTES);
    }

    public static function fromRoute(string $route, string $reason): ?self
    {
        $route = u($route)->trim()->lower()->toString();
        $reason = u($reason)->replaceMatches('/\s+/', ' ')->trim()->toString();
        $configuration = self::ROUTES[$route] ?? self::LEGACY_ROUTES[$route] ?? null;
        if (null === $configuration || '' === $reason) {
            return null;
        }

        return new self(
            $route,
            $configuration['runner'],
            $configuration['model'],
            $configuration['effort'],
            $configuration['display'],
            u($reason)->slice(0, 240)->toString(),
        );
    }

    public static function mainLuna(string $reason = 'Demande courante traitée directement par l’agent principal.'): self
    {
        return self::fromRoute('luna-max', $reason) ?? throw new \LogicException('The Luna route must be valid.');
    }

    public static function fallback(string $reason = 'General task handled directly with the default GPT-6 Sol profile.'): self
    {
        return self::fromRoute(self::FALLBACK_ROUTE, $reason) ?? throw new \LogicException('The fallback route must be valid.');
    }
}
