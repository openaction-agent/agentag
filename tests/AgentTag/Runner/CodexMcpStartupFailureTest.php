<?php

namespace App\Tests\AgentTag\Runner;

use App\AgentTag\Runner\CodexJsonEventParser;
use PHPUnit\Framework\TestCase;

final class CodexMcpStartupFailureTest extends TestCase
{
    public function testItReportsAndDeduplicatesMcpStartupFailuresFromStderr(): void
    {
        $parser = new CodexJsonEventParser();

        self::assertSame([], $parser->consumeStderr("MCP startup failed: request timed out\nMCP startup failed: request timed out\n"));
        $events = $parser->flush();

        self::assertCount(1, $events);
        self::assertSame('mcp_startup_failed', $events[0]->type());
        self::assertSame('An MCP server did not load; the task will continue without its tools.', $events[0]->message());
        self::assertSame([], $events[0]->context());
    }

    public function testItReportsMcpStartupFailuresFromJsonEvents(): void
    {
        $parser = new CodexJsonEventParser();

        $events = $parser->consume("{\"type\":\"mcp_server_startup_failed\",\"server_name\":\"oa-ecologistes\",\"error\":\"startup timeout\"}\n");

        self::assertCount(1, $events);
        self::assertSame('mcp_startup_failed', $events[0]->type());
        self::assertSame(['server' => 'oa-ecologistes'], $events[0]->context());
    }

    public function testItReportsAnUnattributedRmcpTransportFailure(): void
    {
        $parser = new CodexJsonEventParser();

        self::assertSame([], $parser->consumeStderr("ERROR rmcp::transport::worker: worker quit with fatal: Transport channel closed, when UnexpectedServerResponse(\"HTTP 403\")\n"));
        $events = $parser->flush();

        self::assertCount(1, $events);
        self::assertSame('An MCP server did not load; the task will continue without its tools.', $events[0]->message());
        self::assertSame([], $events[0]->context());
    }

    public function testItUsesTheServerReportedByTheAgentWhenCodexStderrIsUnattributed(): void
    {
        $parser = new CodexJsonEventParser();

        self::assertSame([], $parser->consumeStderr("MCP startup failed: request timed out\n"));
        $events = $parser->consume("{\"type\":\"item.completed\",\"item\":{\"type\":\"agent_message\",\"text\":\"Aucun serveur `oa_ecologistes` n’est exposé dans cette session.\"}}\n");

        self::assertCount(1, $events);
        self::assertSame('The MCP server "oa-ecologistes" did not load; the task will continue without its tools.', $events[0]->message());
        self::assertSame(['server' => 'oa-ecologistes'], $events[0]->context());
        self::assertSame([], $parser->flush());
    }

    public function testItDoesNotTreatSuccessfulMcpResultDocumentationAsAStartupFailure(): void
    {
        $parser = new CodexJsonEventParser();
        $event = json_encode([
            'type' => 'item.completed',
            'item' => [
                'type' => 'mcp_tool_call',
                'server' => 'codex',
                'result' => ['text' => 'MCP tool. Skipping it causes hard-to-debug failures when tools load.'],
                'error' => null,
                'status' => 'completed',
            ],
        ], \JSON_THROW_ON_ERROR);

        self::assertSame([], $parser->consume($event."\n"));
        self::assertSame([], $parser->flush());
    }

    public function testItDoesNotTreatFailedCommandOutputContainingMcpSourceAsAStartupFailure(): void
    {
        $parser = new CodexJsonEventParser();
        $event = json_encode([
            'type' => 'item.completed',
            'item' => [
                'type' => 'command_execution',
                'command' => 'rg OrganizationScope console/src',
                'aggregated_output' => <<<'OUTPUT'
console/src/Ai/Mcp/McpContactAccess.php-20-public function resolve(Project $project, string $uuid): Contact
Tests failed because a fixture could not load.
OUTPUT,
                'exit_code' => 2,
                'status' => 'failed',
            ],
        ], \JSON_THROW_ON_ERROR);

        self::assertSame([], $parser->consume($event."\n"));
        self::assertSame([], $parser->flush());
    }

    public function testItDoesNotInferAServerNameFromOrdinaryAgentProse(): void
    {
        $parser = new CodexJsonEventParser();
        $event = json_encode([
            'type' => 'item.completed',
            'item' => [
                'type' => 'agent_message',
                'text' => 'L’instance Écologistes n’est pas exposée par le connecteur disponible dans cette session.',
            ],
        ], \JSON_THROW_ON_ERROR);

        $events = $parser->consume($event."\n");

        self::assertCount(1, $events);
        self::assertSame('agent_message', $events[0]->type());
        self::assertSame([], $parser->flush());
    }
}
