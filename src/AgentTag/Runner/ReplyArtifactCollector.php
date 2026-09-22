<?php

namespace App\AgentTag\Runner;

use Psr\Log\LoggerInterface;

use function Symfony\Component\String\u;

final readonly class ReplyArtifactCollector
{
    public const DIRECTORY = 'reply-files';
    public const MAX_FILES = 5;
    public const MAX_FILE_BYTES = 104_857_600;

    public function __construct(
        private ?LoggerInterface $logger = null,
    ) {
    }

    /** @return list<AgentArtifact> */
    public function collect(string $artifactsDirectory): array
    {
        $replyDirectory = $artifactsDirectory.'/'.self::DIRECTORY;
        $resolvedDirectory = realpath($replyDirectory);
        if (false === $resolvedDirectory || !is_dir($resolvedDirectory) || is_link($replyDirectory)) {
            return [];
        }

        $entries = scandir($resolvedDirectory);
        if (false === $entries) {
            return [];
        }

        $artifacts = [];
        foreach ($entries as $name) {
            if ('.' === $name || '..' === $name || u($name)->startsWith('.') || u($name)->endsWith(['.part', '.tmp'])) {
                continue;
            }

            $path = $resolvedDirectory.'/'.$name;
            $resolvedPath = realpath($path);
            $stat = lstat($path);
            if (false === $resolvedPath
                || !u($resolvedPath)->startsWith($resolvedDirectory.'/')
                || false === $stat
                || is_link($path)
                || !is_file($resolvedPath)
                || 0100000 !== ($stat['mode'] & 0170000)) {
                $this->logger?->warning('Ignored an unsafe reply artifact.', ['path' => $path]);

                continue;
            }

            $size = filesize($resolvedPath);
            if (false === $size || $size > self::MAX_FILE_BYTES) {
                $this->logger?->warning('Ignored an oversized reply artifact.', [
                    'path' => $resolvedPath,
                    'size' => $size,
                    'max_size' => self::MAX_FILE_BYTES,
                ]);

                continue;
            }

            $sha256 = hash_file('sha256', $resolvedPath);
            if (false === $sha256) {
                $this->logger?->warning('Could not hash a reply artifact.', ['path' => $resolvedPath]);

                continue;
            }

            $artifacts[] = new AgentArtifact($resolvedPath, $name, $size, $sha256);
            if (self::MAX_FILES === count($artifacts)) {
                break;
            }
        }

        if (count($entries) - 2 > self::MAX_FILES) {
            $this->logger?->warning('Reply artifact collection reached the per-post file limit.', [
                'directory' => $resolvedDirectory,
                'max_files' => self::MAX_FILES,
            ]);
        }

        return $artifacts;
    }
}
