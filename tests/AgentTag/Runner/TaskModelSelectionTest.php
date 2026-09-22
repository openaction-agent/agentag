<?php

namespace App\Tests\AgentTag\Runner;

use App\AgentTag\Runner\TaskModelSelection;
use PHPUnit\Framework\TestCase;

use function Symfony\Component\String\u;

final class TaskModelSelectionTest extends TestCase
{
    public function testItBoundsTheReasonByUnicodeCharacters(): void
    {
        $selection = TaskModelSelection::fromRoute('GPT-6-SOL-HIGH', u('é')->repeat(300)->toString());

        self::assertNotNull($selection);
        self::assertSame(240, u($selection->reason)->length());
        self::assertSame(u('é')->repeat(240)->toString(), $selection->reason);
    }

    public function testItMapsOpusRoutesToClaudeCode(): void
    {
        $selection = TaskModelSelection::fromRoute('opus-5-5-high', 'Epic-scale implementation.');

        self::assertNotNull($selection);
        self::assertSame(TaskModelSelection::RUNNER_CLAUDE, $selection->runner);
        self::assertSame('claude-opus-5-5', $selection->model);
        self::assertSame('high', $selection->effort);
        self::assertSame('Claude Opus 5.5', $selection->displayModel);
    }

    public function testItKeepsLegacyRoutesResolvableOnCodexButDoesNotOfferThem(): void
    {
        $selection = TaskModelSelection::fromRoute('terra-high', 'Selected before the GPT-6 routing.');

        self::assertNotNull($selection);
        self::assertSame(TaskModelSelection::RUNNER_CODEX, $selection->runner);
        self::assertSame('gpt-5.6-terra', $selection->model);
        self::assertNotContains('terra-high', TaskModelSelection::selectableRoutes());
    }

    public function testTheFallbackIsGpt6SolHigh(): void
    {
        $selection = TaskModelSelection::fallback();

        self::assertSame('gpt-6-sol-high', $selection->route);
        self::assertSame(TaskModelSelection::RUNNER_CODEX, $selection->runner);
    }
}
