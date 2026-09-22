<?php

namespace App\AgentTag\Workspace;

use function Symfony\Component\String\u;

final readonly class WorkspaceLayout
{
    public function __construct(
        private string $workspacePath,
    ) {
        $this->assertAbsolutePath($workspacePath, 'workspace path');
    }

    public function workspacePath(): string
    {
        return $this->normalize($this->workspacePath);
    }

    public function runtimeRootPath(): string
    {
        return \dirname($this->workspacePath());
    }

    public function runsPath(): string
    {
        return $this->runtimeRootPath().'/runs';
    }

    public function runPath(string $runId): string
    {
        return $this->runsPath().'/'.$this->safeSegment($runId);
    }

    public function sessionPath(string $sessionKey): string
    {
        return $this->runsPath().'/session-'.u(sha1($sessionKey))->slice(0, 16)->toString();
    }

    public function artifactsPath(string $runId): string
    {
        return $this->runtimeRootPath().'/artifacts/'.$this->safeSegment($runId);
    }

    public function inputFilesPath(string $runId): string
    {
        return $this->artifactsPath($runId).'/input-files';
    }

    private function assertAbsolutePath(string $path, string $label): void
    {
        if (!u($path)->startsWith('/')) {
            throw new \InvalidArgumentException(sprintf('AgentTag %s must be absolute, got "%s".', $label, $path));
        }
    }

    private function normalize(string $path): string
    {
        return u($path)->trimEnd('/')->toString();
    }

    private function safeSegment(string $segment): string
    {
        if ('' === $segment || u($segment)->containsAny(['/', '..'])) {
            throw new \InvalidArgumentException(sprintf('Invalid workspace path segment "%s".', $segment));
        }

        return $segment;
    }
}
