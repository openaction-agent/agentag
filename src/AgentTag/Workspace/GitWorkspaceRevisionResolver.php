<?php

namespace App\AgentTag\Workspace;

use Symfony\Component\Process\Process;

use function Symfony\Component\String\u;

final readonly class GitWorkspaceRevisionResolver implements WorkspaceRevisionResolver
{
    #[\Override]
    public function revisionFor(string $workspaceDirectory): ?string
    {
        if (!is_dir($workspaceDirectory.'/.git')) {
            return null;
        }

        $process = new Process(['git', '-C', $workspaceDirectory, 'rev-parse', '--short', 'HEAD']);
        $process->run();

        return 0 === $process->getExitCode() ? u($process->getOutput())->trim()->toString() : null;
    }
}
