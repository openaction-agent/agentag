<?php

namespace App\Tests\Entity;

use App\Entity\AgentRun;
use App\Entity\ChatSession;
use PHPUnit\Framework\TestCase;

use function Symfony\Component\String\u;

final class AgentRunTest extends TestCase
{
    public function testItBoundsTheCurrentStageByUnicodeCharacters(): void
    {
        $session = new ChatSession('session', 'team', 'channel', 'thread', new \DateTimeImmutable());
        $run = new AgentRun($session, AgentRun::STATUS_RUNNING, new \DateTimeImmutable());

        $run->updateStage(u('é')->repeat(300)->toString());

        self::assertNotNull($run->currentStage());
        self::assertSame(240, u($run->currentStage())->length());
        self::assertSame(u('é')->repeat(240)->toString(), $run->currentStage());
    }
}
