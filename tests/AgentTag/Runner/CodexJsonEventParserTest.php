<?php

namespace App\Tests\AgentTag\Runner;

use App\AgentTag\Runner\CodexJsonEventParser;
use PHPUnit\Framework\TestCase;

final class CodexJsonEventParserTest extends TestCase
{
    public function testItWaitsForACompleteUtf8CharacterAcrossChunks(): void
    {
        $parser = new CodexJsonEventParser();

        self::assertSame([], $parser->consume("{\"type\":\"agent_message\",\"message\":\"Done \xF0\x9F"));
        $events = $parser->consume("\x99\x82".'"}'."\n");

        self::assertCount(1, $events);
        self::assertSame('Done 🙂', $events[0]->message());
    }
}
