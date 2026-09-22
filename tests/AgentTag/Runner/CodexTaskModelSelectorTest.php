<?php

namespace App\Tests\AgentTag\Runner;

use App\AgentTag\Configuration\AgentTagSettings;
use App\AgentTag\Runner\CodexTaskModelSelector;
use App\AgentTag\Runner\ProcessFactory;
use App\AgentTag\Runner\RunnerProcess;
use PHPUnit\Framework\TestCase;

final class CodexTaskModelSelectorTest extends TestCase
{
    public function testItUsesEphemeralLunaMaxAndAConstrainedOutputSchema(): void
    {
        $factory = new ModelSelectionProcessFactory('{"route":"opus-5-5-medium","selection_reason":"Precise, verifiable bug fix."}');
        $selector = new CodexTaskModelSelector($factory, new AgentTagSettings('@Codex', '/tmp', modelSelectionModel: 'gpt-6-luna'));

        $selection = $selector->select('@Codex implement the billing fix');

        self::assertSame('opus-5-5-medium', $selection->route);
        self::assertSame('claude', $selection->runner);
        self::assertSame('claude-opus-5-5', $selection->model);
        self::assertSame('medium', $selection->effort);
        self::assertSame('Precise, verifiable bug fix.', $selection->reason);
        self::assertContains('gpt-6-luna', $factory->command);
        self::assertContains('model_reasoning_effort="max"', $factory->command);
        self::assertContains('--ephemeral', $factory->command);
        self::assertContains('--output-schema', $factory->command);
        $schema = json_decode($factory->schema, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($schema);
        $properties = $schema['properties'] ?? null;
        self::assertIsArray($properties);
        $route = $properties['route'] ?? null;
        self::assertIsArray($route);
        self::assertSame([
            'gpt-6-luna-medium',
            'gpt-6-sol-high',
            'gpt-6-sol-max',
            'gpt-6-astra-medium',
            'gpt-6-astra-max',
            'opus-5-5-medium',
            'opus-5-5-high',
            'opus-5-5-max',
        ], $route['enum'] ?? null);
        self::assertStringContainsString('Honor an explicit request for a model or route.', $factory->input);
        self::assertStringContainsString('gpt-6-sol-max, gpt-6-astra-max and opus-5-5-max are used only when explicitly requested.', $factory->input);
        self::assertStringContainsString('- gpt-6-luna-medium: control and meta messages only', $factory->input);
        self::assertStringContainsString('- opus-5-5-medium: default for technical implementation and specification writing:', $factory->input);
        self::assertStringContainsString('- opus-5-5-high: large-scale implementation spanning a whole epic', $factory->input);
        self::assertStringContainsString('- gpt-6-astra-medium: code review of a PR', $factory->input);
        self::assertStringContainsString('- gpt-6-sol-high: everything else, including:', $factory->input);
        self::assertStringContainsString('Precedence: explicit model or route request, then opus-5-5-high, then gpt-6-astra-medium, then opus-5-5-medium, then gpt-6-luna-medium, then gpt-6-sol-high.', $factory->input);
        self::assertStringEndsWith("User request:\n@Codex implement the billing fix", $factory->input);
    }

    public function testItSupportsEveryRoutingProfile(): void
    {
        $profiles = [
            'gpt-6-luna-medium' => ['codex', 'gpt-6-luna', 'medium'],
            'gpt-6-sol-high' => ['codex', 'gpt-6-sol', 'high'],
            'gpt-6-sol-max' => ['codex', 'gpt-6-sol', 'max'],
            'gpt-6-astra-medium' => ['codex', 'gpt-6-astra', 'medium'],
            'gpt-6-astra-max' => ['codex', 'gpt-6-astra', 'max'],
            'opus-5-5-medium' => ['claude', 'claude-opus-5-5', 'medium'],
            'opus-5-5-high' => ['claude', 'claude-opus-5-5', 'high'],
            'opus-5-5-max' => ['claude', 'claude-opus-5-5', 'max'],
        ];

        foreach ($profiles as $route => [$runner, $model, $effort]) {
            $factory = new ModelSelectionProcessFactory(json_encode([
                'route' => $route,
                'selection_reason' => 'Test selection.',
            ], \JSON_THROW_ON_ERROR));
            $selector = new CodexTaskModelSelector($factory, new AgentTagSettings('@Codex', '/tmp'));

            $selection = $selector->select('route this request');

            self::assertSame($route, $selection->route);
            self::assertSame($runner, $selection->runner);
            self::assertSame($model, $selection->model);
            self::assertSame($effort, $selection->effort);
        }
    }

    public function testItRejectsLegacyRoutesFromTheSelector(): void
    {
        $factory = new ModelSelectionProcessFactory('{"route":"terra-high","selection_reason":"Legacy route."}');
        $selector = new CodexTaskModelSelector($factory, new AgentTagSettings('@Codex', '/tmp'));

        $selection = $selector->select('inspect this');

        self::assertSame('gpt-6-sol-high', $selection->route);
        self::assertSame('The model selector was unavailable, so the general-purpose route was used.', $selection->reason);
    }

    public function testItUsesTheFallbackRouteWhenTheGeneratedRouteIsInvalid(): void
    {
        $factory = new ModelSelectionProcessFactory('{"route":"unknown","selection_reason":"Unclear."}');
        $selector = new CodexTaskModelSelector($factory, new AgentTagSettings('@Codex', '/tmp'));

        $selection = $selector->select('inspect this');

        self::assertSame('gpt-6-sol-high', $selection->route);
        self::assertSame('gpt-6-sol', $selection->model);
        self::assertSame('high', $selection->effort);
    }

    public function testItUsesTheFallbackRouteWhenTheClassifierOutputIsNotJson(): void
    {
        $selector = new CodexTaskModelSelector(new ModelSelectionProcessFactory('not-json'), new AgentTagSettings('@Codex', '/tmp'));

        $selection = $selector->select('@Codex haz algo');

        self::assertSame('gpt-6-sol-high', $selection->route);
        self::assertSame('The model selector was unavailable, so the general-purpose route was used.', $selection->reason);
    }
}

final class ModelSelectionProcessFactory implements ProcessFactory
{
    /** @var list<string> */
    public array $command = [];

    public string $schema = '';

    public string $input = '';

    public function __construct(private readonly string $response)
    {
    }

    #[\Override]
    public function create(array $command, string $workingDirectory, array $environment, string $input, int $timeoutSeconds): RunnerProcess
    {
        $this->command = $command;
        $this->input = $input;
        $schemaIndex = array_search('--output-schema', $command, true);
        if (is_int($schemaIndex)) {
            $this->schema = (string) file_get_contents($command[$schemaIndex + 1]);
        }

        return new ModelSelectionRunnerProcess($command, $this->response);
    }
}

final class ModelSelectionRunnerProcess implements RunnerProcess
{
    /** @param list<string> $command */
    public function __construct(private readonly array $command, private readonly string $response)
    {
    }

    #[\Override]
    public function run(?callable $callback = null): int
    {
        return 0;
    }

    #[\Override]
    public function start(?callable $callback = null): void
    {
        $index = array_search('--output-last-message', $this->command, true);
        if (is_int($index)) {
            file_put_contents($this->command[$index + 1], $this->response);
        }
    }

    #[\Override]
    public function wait(?callable $callback = null): int
    {
        return 0;
    }

    #[\Override]
    public function isRunning(): bool
    {
        return false;
    }

    #[\Override]
    public function stop(float $timeout = 10.0): int
    {
        return 0;
    }

    #[\Override]
    public function exitCode(): int
    {
        return 0;
    }

    #[\Override]
    public function output(): string
    {
        return '';
    }

    #[\Override]
    public function errorOutput(): string
    {
        return '';
    }
}
