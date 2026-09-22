<?php

namespace App\Tests\AgentTag\Runner;

use App\AgentTag\Runner\TaskContinuationParser;
use PHPUnit\Framework\TestCase;

final class TaskContinuationParserTest extends TestCase
{
    public function testItRemovesTheContinuationMetadataAfterUnicodeText(): void
    {
        $result = (new TaskContinuationParser())->parse(
            'Terminé 🙂 <!-- agentag:{"action":"wait","seconds":60,"reason":"CI"} -->',
        );

        self::assertSame('Terminé 🙂', $result['message']);
        self::assertNotNull($result['continuation']);
    }
}
