<?php

namespace App\Tests\AgentTag\Session;

use App\AgentTag\Agent\AgentProfile;
use App\AgentTag\Session\ChatThreadContext;
use App\AgentTag\Session\SessionContextSnapshotBuilder;
use App\Entity\ChatSession;
use PHPUnit\Framework\TestCase;

use function Symfony\Component\String\u;

final class SessionContextSnapshotBuilderTest extends TestCase
{
    public function testItBoundsTheSnapshotByUnicodeCharacters(): void
    {
        $snapshot = (new SessionContextSnapshotBuilder(1000))->build(
            new ChatSession('session', 'team', 'channel', 'thread', new \DateTimeImmutable()),
            ChatThreadContext::single('post', 'user', u('🙂')->repeat(1200)->toString()),
            [],
            new AgentProfile('agent', '/workspace', null, 'workspace-write', 60),
        );

        self::assertSame(1000, u($snapshot)->length());
        self::assertTrue(u($snapshot)->endsWith("\n[Context truncated to 1000 characters.]"));
        self::assertTrue(mb_check_encoding($snapshot, 'UTF-8'));
    }
}
