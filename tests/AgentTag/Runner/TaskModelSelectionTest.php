<?php

namespace App\Tests\AgentTag\Runner;

use App\AgentTag\Runner\TaskModelSelection;
use PHPUnit\Framework\TestCase;

use function Symfony\Component\String\u;

final class TaskModelSelectionTest extends TestCase
{
    public function testItBoundsTheReasonByUnicodeCharacters(): void
    {
        $selection = TaskModelSelection::fromRoute('SOL-MEDIUM', u('é')->repeat(300)->toString());

        self::assertNotNull($selection);
        self::assertSame(240, u($selection->reason)->length());
        self::assertSame(u('é')->repeat(240)->toString(), $selection->reason);
    }
}
