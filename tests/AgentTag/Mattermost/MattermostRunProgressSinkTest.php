<?php

namespace App\Tests\AgentTag\Mattermost;

use App\AgentTag\Mattermost\MattermostInboundEvent;
use App\AgentTag\Mattermost\MattermostNotifier;
use App\AgentTag\Mattermost\MattermostRunProgressSink;
use App\AgentTag\Mattermost\TaskCardRenderer;
use App\AgentTag\Runner\AgentRunnerProgress;
use App\AgentTag\Runner\TaskModelSelection;
use App\Entity\AgentRun;
use App\Entity\ChatSession;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

use function Symfony\Component\String\u;

final class MattermostRunProgressSinkTest extends TestCase
{
    public function testItUpdatesOneTaskCardFromMeaningfulAgentMessages(): void
    {
        $notifier = new ProgressTraceableMattermostNotifier();
        $run = $this->task();
        $sink = $this->sink($notifier, $run);

        $sink->onProgress(new AgentRunnerProgress('command_execution', 'phpunit output'));
        $sink->onProgress(new AgentRunnerProgress('agent_message', 'Reproduced three billing test failures. I am tracing rounding now.'));

        self::assertCount(1, $notifier->updatedPosts);
        foreach (u($notifier->updatedPosts[0])->split("\n") as $line) {
            self::assertStringStartsWith('> ', $line->toString());
        }
        self::assertStringContainsString('Reproduced three billing test failures.', $notifier->updatedPosts[0]);
        self::assertStringContainsString('Model: **GPT-5.6 Luna · max**', $notifier->updatedPosts[0]);
        self::assertStringNotContainsString('phpunit output', $notifier->updatedPosts[0]);
        $attachments = $notifier->updatedProps[0]['attachments'] ?? null;
        self::assertIsArray($attachments);
        $attachment = $attachments[0] ?? null;
        self::assertIsArray($attachment);
        $actions = $attachment['actions'] ?? null;
        self::assertIsArray($actions);
        self::assertSame(['Stop'], array_column($actions, 'name'));
        self::assertSame([], $notifier->threadMessages);
    }

    public function testItShowsTheDirectlySelectedSolProfile(): void
    {
        $notifier = new ProgressTraceableMattermostNotifier();
        $run = $this->task(TaskModelSelection::fromRoute('sol-medium', 'General non-coding task.'));
        $sink = $this->sink($notifier, $run);

        $sink->onProgress(new AgentRunnerProgress('agent_message', 'I am preparing the requested summary.'));

        self::assertCount(1, $notifier->updatedPosts);
        self::assertStringContainsString('Model: **GPT-5.6 Sol · medium** — General non-coding task.', $notifier->updatedPosts[0]);
    }

    public function testItPostsOneMcpStartupWarningWithoutChangingTheTaskCard(): void
    {
        $notifier = new ProgressTraceableMattermostNotifier();
        $sink = $this->sink($notifier, $this->task());
        $failure = new AgentRunnerProgress(
            'mcp_startup_failed',
            'The MCP server "oa-ecologistes" did not load; the task will continue without its tools.',
            ['server' => 'oa-ecologistes'],
        );

        $sink->onProgress($failure);
        $sink->onProgress($failure);

        self::assertSame(['⚠️ The MCP server "oa-ecologistes" did not load; the task will continue without its tools.'], $notifier->threadMessages);
        self::assertSame([], $notifier->updatedPosts);
    }

    public function testFinishKeepsTheStepsAndPostsTheAnswerAfterTheCardOnlyOnce(): void
    {
        $notifier = new ProgressTraceableMattermostNotifier();
        $run = $this->task();
        $run->recordRunnerResult(AgentRun::STATUS_COMPLETED, "Cause\nRounding order.\n\nVerification\n• 428 tests passed", '', '/tmp/workspace', [], 0, null);
        $sink = $this->sink($notifier, $run);

        $sink->finish();
        $sink->finish();

        self::assertCount(2, $notifier->updatedPosts);
        self::assertStringContainsString('✅ **Fix billing tests**', $notifier->updatedPosts[0]);
        self::assertStringContainsString('✓ Workspace ready', $notifier->updatedPosts[0]);
        self::assertStringContainsString('✓ Complete task and verify results', $notifier->updatedPosts[0]);
        self::assertStringContainsString('Model: **GPT-5.6 Luna · max**', $notifier->updatedPosts[0]);
        self::assertStringNotContainsString('428 tests passed', $notifier->updatedPosts[0]);
        self::assertSame([], $notifier->updatedProps[0]['attachments']);
        self::assertSame(["Cause\nRounding order.\n\nVerification\n• 428 tests passed"], $notifier->createdMessages);
        self::assertFalse(u($notifier->createdMessages[0])->startsWith('> '));
        self::assertSame('answer-post', $run->answerPostId());
    }

    public function testFinishUploadsReplyArtifactsOnceAndAttachesTheirIds(): void
    {
        $directory = sys_get_temp_dir().'/agentag-reply-files-'.bin2hex(random_bytes(6)).'/reply-files';
        mkdir($directory, 0770, true);
        $path = $directory.'/report.csv';
        file_put_contents($path, "name,value\nA,1\n");
        $contents = (string) file_get_contents($path);
        $notifier = new ProgressTraceableMattermostNotifier();
        $run = $this->task();
        $run->recordRunnerResult(AgentRun::STATUS_COMPLETED, 'Report generated.', '', '/tmp/workspace', [[
            'path' => $path,
            'name' => 'report.csv',
            'size' => (int) filesize($path),
            'sha256' => hash('sha256', $contents),
        ]], 0, null);
        $sink = $this->sink($notifier, $run);

        try {
            $sink->finish();
            $sink->finish();
        } finally {
            unlink($path);
            rmdir($directory);
            rmdir(dirname($directory));
        }

        self::assertSame([$path], $notifier->uploadedFiles);
        self::assertSame([['file-1']], $notifier->createdFileIds);
        self::assertSame('answer-post', $run->answerPostId());
    }

    public function testRetryReusesUploadedFileIdsWhenCreatingThePostInitiallyFails(): void
    {
        $directory = sys_get_temp_dir().'/agentag-reply-retry-'.bin2hex(random_bytes(6)).'/reply-files';
        mkdir($directory, 0770, true);
        $path = $directory.'/report.txt';
        file_put_contents($path, 'ready');
        $notifier = new ProgressTraceableMattermostNotifier();
        $notifier->createPostResults = [null, 'answer-post'];
        $run = $this->task();
        $run->recordRunnerResult(AgentRun::STATUS_COMPLETED, 'Report generated.', '', '/tmp/workspace', [[
            'path' => $path,
            'name' => 'report.txt',
            'size' => 5,
            'sha256' => hash('sha256', 'ready'),
        ]], 0, null);
        $sink = $this->sink($notifier, $run);

        try {
            try {
                $sink->finish();
                self::fail('The first Mattermost post attempt should fail.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('final reply delivery failed', $exception->getMessage());
            }
            $sink->finish();
        } finally {
            unlink($path);
            rmdir($directory);
            rmdir(dirname($directory));
        }

        self::assertSame([$path], $notifier->uploadedFiles);
        self::assertSame([['file-1'], ['file-1']], $notifier->createdFileIds);
        self::assertSame('answer-post', $run->answerPostId());
    }

    private function sink(ProgressTraceableMattermostNotifier $notifier, AgentRun $run): MattermostRunProgressSink
    {
        return new MattermostRunProgressSink(
            $notifier,
            new MattermostInboundEvent('post', '', 'post', '', 'channel', 'O', 'team', 'user', ''),
            $run,
            $this->renderer(),
            $this->createStub(EntityManagerInterface::class),
            minimumIntervalSeconds: 0,
        );
    }

    private function task(?TaskModelSelection $selection = null): AgentRun
    {
        $run = new AgentRun(new ChatSession('mattermost:t:c:p', 't', 'c', 'p', new \DateTimeImmutable()), AgentRun::STATUS_RUNNING, new \DateTimeImmutable(), requesterId: 'user', workspacePath: '/tmp/workspace');
        (new \ReflectionProperty(AgentRun::class, 'id'))->setValue($run, 4);
        $run->initializeTask('Fix billing tests', 'Workspace ready', 'thomas', new \DateTimeImmutable('+1 day'), 2, 60, 'milestones', $selection);
        $run->assignTaskPost('task-post');

        return $run;
    }

    private function renderer(): TaskCardRenderer
    {
        $routes = new RouteCollection();
        $routes->add('agentag_mattermost_action', new Route('/integrations/mattermost/action'));

        return new TaskCardRenderer(new UrlGenerator($routes, new RequestContext('', 'GET', 'agentag.test', 'https')), 'secret');
    }
}

final class ProgressTraceableMattermostNotifier implements MattermostNotifier
{
    /** @var list<string> */
    public array $updatedPosts = [];
    /** @var list<string> */
    public array $threadMessages = [];
    /** @var list<string> */
    public array $createdMessages = [];
    /** @var list<list<string>> */
    public array $createdFileIds = [];
    /** @var list<string> */
    public array $uploadedFiles = [];
    /** @var list<string|null> */
    public array $createPostResults = [];
    /** @var list<array<string, mixed>> */
    public array $updatedProps = [];

    #[\Override]
    public function showTyping(MattermostInboundEvent $event): void
    {
    }

    #[\Override]
    public function postProgress(MattermostInboundEvent $event, string $message): void
    {
        $this->threadMessages[] = $message;
    }

    #[\Override]
    public function uploadFile(MattermostInboundEvent $event, string $path): string
    {
        $this->uploadedFiles[] = $path;

        return 'file-'.count($this->uploadedFiles);
    }

    #[\Override]
    public function createPost(MattermostInboundEvent $event, string $message, array $props = [], array $fileIds = []): ?string
    {
        $this->createdMessages[] = $message;
        $this->createdFileIds[] = $fileIds;

        return [] === $this->createPostResults ? 'answer-post' : array_shift($this->createPostResults);
    }

    #[\Override]
    public function updatePost(string $postId, string $message, array $props = []): bool
    {
        $this->updatedPosts[] = $message;
        $this->updatedProps[] = $props;

        return true;
    }
}
