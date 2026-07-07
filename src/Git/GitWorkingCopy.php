<?php

declare(strict_types=1);

namespace Jbrada\Semverdict\Git;

use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Read-only view of a git working copy for the `next` command: lists tags,
 * exports a tagged tree, and snapshots the current working tree so the two
 * can be compared as plain directories.
 */
final class GitWorkingCopy
{
    private function __construct(
        private readonly string $topLevel,
        /** Package dir relative to the repo root: '' or with a trailing slash (git's --show-prefix format). */
        private readonly string $prefix,
    ) {
    }

    /**
     * @param string $path package root: the repo root itself or any directory inside it (monorepo module)
     *
     * @throws GitException when the path does not exist or is not inside a git work tree
     */
    public static function open(string $path): self
    {
        $real = realpath($path);
        if ($real === false || !is_dir($real)) {
            throw new GitException("Not a directory: {$path}");
        }

        $lines = explode("\n", trim(self::git(['rev-parse', '--show-toplevel', '--show-prefix'], $real)));
        if ($lines[0] === '') {
            throw new GitException("Cannot resolve the git repository at {$path}.");
        }

        return new self($lines[0], $lines[1] ?? '');
    }

    /**
     * Absolute path of the package root inside the working copy.
     */
    public function packageDir(): string
    {
        return rtrim($this->topLevel . '/' . $this->prefix, '/');
    }

    /**
     * @return list<string>
     *
     * @throws GitException
     */
    public function tags(): array
    {
        $output = trim(self::git(['tag', '--list'], $this->topLevel));

        return $output === '' ? [] : explode("\n", $output);
    }

    public function tagExists(string $tag): bool
    {
        try {
            self::git(['rev-parse', '--verify', '--quiet', "refs/tags/{$tag}"], $this->topLevel);

            return true;
        } catch (GitException) {
            return false;
        }
    }

    /**
     * Exports the package tree as of $tag into $destDir, returning the package
     * root inside it. Uses `git archive` (zip), so only committed content is
     * exported — exactly what the tag's consumers got.
     *
     * @throws GitException
     */
    public function exportTag(string $tag, string $destDir): string
    {
        self::makeDir($destDir);
        $zipPath = $destDir . '/.export.zip';
        $args = ['archive', '--format=zip', '-o', $zipPath, $tag];
        if ($this->prefix !== '') {
            $args[] = '--';
            $args[] = $this->prefix;
        }
        self::git($args, $this->topLevel);

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new GitException("Cannot open the exported archive for {$tag}.");
        }
        if ($zip->numFiles > 0 && !$zip->extractTo($destDir)) {
            $zip->close();
            throw new GitException("Cannot extract the exported archive for {$tag}.");
        }
        $zip->close();
        unlink($zipPath);

        $root = rtrim($destDir . '/' . $this->prefix, '/');
        if (!is_dir($root)) {
            throw new GitException("Tag {$tag} does not contain {$this->prefix}.");
        }

        return $root;
    }

    /**
     * Copies the package's current working tree into $destDir: tracked files
     * (with any uncommitted edits) plus untracked-but-not-ignored ones, so
     * vendor/, build artifacts and .git never pollute the comparison.
     *
     * @throws GitException
     */
    public function exportWorkingTree(string $destDir): void
    {
        self::makeDir($destDir);
        $packageDir = $this->packageDir();
        // Run inside the package dir: ls-files then lists only its subtree, relative to it.
        $list = self::git(['ls-files', '-z', '--cached', '--others', '--exclude-standard'], $packageDir);
        foreach (explode("\0", $list) as $relative) {
            $source = $packageDir . '/' . $relative;
            if ($relative === '' || !is_file($source)) {
                continue; // tracked but deleted from the working tree
            }
            $target = $destDir . '/' . $relative;
            self::makeDir(dirname($target));
            if (!copy($source, $target)) {
                throw new GitException("Cannot copy {$relative} into the working-tree snapshot.");
            }
        }
    }

    /**
     * @param list<string> $args
     *
     * @throws GitException
     */
    private static function git(array $args, string $cwd): string
    {
        $process = new Process(['git', ...$args], cwd: $cwd, timeout: 120);
        try {
            $process->run();
        } catch (\Throwable $e) {
            throw new GitException("git failed: {$e->getMessage()}", previous: $e);
        }
        if (!$process->isSuccessful()) {
            $error = trim($process->getErrorOutput()) ?: trim($process->getOutput()) ?: 'unknown error';

            throw new GitException('git ' . ($args[0] ?? '') . ' failed: ' . substr($error, 0, 500));
        }

        return $process->getOutput();
    }

    /**
     * @throws GitException
     */
    private static function makeDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new GitException("Cannot create directory {$dir}.");
        }
    }
}
