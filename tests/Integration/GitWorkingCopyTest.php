<?php

declare(strict_types=1);

namespace Jbrada\Semverdict\Tests\Integration;

use Jbrada\Semverdict\Git\GitException;
use Jbrada\Semverdict\Git\GitWorkingCopy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class GitWorkingCopyTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/semverdict-git-' . uniqid();
        mkdir($this->workDir, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->workDir));
    }

    public function testTagAndWorkingTreeExportsDiverge(): void
    {
        $repo = $this->workDir . '/repo';
        mkdir($repo . '/src', 0777, true);
        file_put_contents($repo . '/.gitignore', "vendor/\n");
        file_put_contents($repo . '/src/Foo.php', '<?php // V1');
        $this->git($repo, 'init', '-q');
        $this->git($repo, 'add', '-A');
        $this->git($repo, 'commit', '-qm', 'v1');
        $this->git($repo, 'tag', 'v1.0.0');

        // Mutate the working tree: edit, add untracked, add ignored.
        file_put_contents($repo . '/src/Foo.php', '<?php // V2');
        file_put_contents($repo . '/src/New.php', '<?php // new');
        mkdir($repo . '/vendor', 0777, true);
        file_put_contents($repo . '/vendor/lib.php', '<?php // dep');

        $workingCopy = GitWorkingCopy::open($repo);

        self::assertSame(realpath($repo), realpath($workingCopy->packageDir()));
        self::assertSame(['v1.0.0'], $workingCopy->tags());
        self::assertTrue($workingCopy->tagExists('v1.0.0'));
        self::assertFalse($workingCopy->tagExists('v9.9.9'));

        $before = $workingCopy->exportTag('v1.0.0', $this->workDir . '/before');
        self::assertStringEqualsFile($before . '/src/Foo.php', '<?php // V1');
        self::assertFileDoesNotExist($before . '/src/New.php');

        $after = $this->workDir . '/after';
        $workingCopy->exportWorkingTree($after);
        self::assertStringEqualsFile($after . '/src/Foo.php', '<?php // V2');
        self::assertFileExists($after . '/src/New.php');
        self::assertDirectoryDoesNotExist($after . '/vendor');
        self::assertDirectoryDoesNotExist($after . '/.git');
    }

    public function testMonorepoSubdirectoryIsScopedToThePackage(): void
    {
        $repo = $this->workDir . '/mono';
        mkdir($repo . '/pkg/src', 0777, true);
        mkdir($repo . '/other', 0777, true);
        file_put_contents($repo . '/pkg/src/Foo.php', '<?php // pkg');
        file_put_contents($repo . '/other/readme.txt', 'not the package');
        $this->git($repo, 'init', '-q');
        $this->git($repo, 'add', '-A');
        $this->git($repo, 'commit', '-qm', 'v1');
        $this->git($repo, 'tag', 'v1.0.0');

        $workingCopy = GitWorkingCopy::open($repo . '/pkg');

        $before = $workingCopy->exportTag('v1.0.0', $this->workDir . '/before');
        self::assertStringEndsWith('/pkg', $before);
        self::assertFileExists($before . '/src/Foo.php');
        self::assertFileDoesNotExist(dirname($before) . '/other/readme.txt');

        $after = $this->workDir . '/after';
        $workingCopy->exportWorkingTree($after);
        self::assertFileExists($after . '/src/Foo.php');
        self::assertDirectoryDoesNotExist($after . '/other');
    }

    public function testOpenFailsOutsideAWorkTree(): void
    {
        $this->expectException(GitException::class);
        GitWorkingCopy::open($this->workDir);
    }

    private function git(string $cwd, string ...$args): void
    {
        $process = new Process(
            ['git', '-c', 'user.email=test@example.com', '-c', 'user.name=Test', '-c', 'commit.gpgsign=false', '-c', 'tag.gpgsign=false', ...$args],
            cwd: $cwd,
        );
        $process->mustRun();
    }
}
