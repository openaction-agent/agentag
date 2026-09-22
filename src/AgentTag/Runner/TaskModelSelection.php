<?php

namespace App\AgentTag\Runner;

use function Symfony\Component\String\u;

final readonly class TaskModelSelection
{
    /** @var array<string, array{model: string, effort: string, display: string}> */
    private const array ROUTES = [
        'luna-high' => ['model' => 'gpt-5.6-luna', 'effort' => 'high', 'display' => 'GPT-5.6 Luna'],
        'luna-max' => ['model' => 'gpt-5.6-luna', 'effort' => 'max', 'display' => 'GPT-5.6 Luna'],
        'luna-medium' => ['model' => 'gpt-5.6-luna', 'effort' => 'medium', 'display' => 'GPT-5.6 Luna'],
        'luna-xhigh' => ['model' => 'gpt-5.6-luna', 'effort' => 'xhigh', 'display' => 'GPT-5.6 Luna'],
        'terra-medium' => ['model' => 'gpt-5.6-terra', 'effort' => 'medium', 'display' => 'GPT-5.6 Terra'],
        'terra-high' => ['model' => 'gpt-5.6-terra', 'effort' => 'high', 'display' => 'GPT-5.6 Terra'],
        'terra-xhigh' => ['model' => 'gpt-5.6-terra', 'effort' => 'xhigh', 'display' => 'GPT-5.6 Terra'],
        'terra-max' => ['model' => 'gpt-5.6-terra', 'effort' => 'max', 'display' => 'GPT-5.6 Terra'],
        'sol-medium' => ['model' => 'gpt-5.6-sol', 'effort' => 'medium', 'display' => 'GPT-5.6 Sol'],
        'sol-high' => ['model' => 'gpt-5.6-sol', 'effort' => 'high', 'display' => 'GPT-5.6 Sol'],
        'sol-xhigh' => ['model' => 'gpt-5.6-sol', 'effort' => 'xhigh', 'display' => 'GPT-5.6 Sol'],
    ];

    private function __construct(
        public string $route,
        public string $model,
        public string $effort,
        public string $displayModel,
        public string $reason,
    ) {
    }

    public static function fromRoute(string $route, string $reason): ?self
    {
        $route = u($route)->trim()->lower()->toString();
        $reason = u($reason)->replaceMatches('/\s+/', ' ')->trim()->toString();
        $configuration = self::ROUTES[$route] ?? null;
        if (null === $configuration || '' === $reason) {
            return null;
        }

        return new self(
            $route,
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

    public static function solMedium(string $reason = 'General task handled directly with the balanced Sol profile.'): self
    {
        return self::fromRoute('sol-medium', $reason) ?? throw new \LogicException('The Sol medium route must be valid.');
    }
}
