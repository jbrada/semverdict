<?php

declare(strict_types=1);

namespace Jbrada\Semverdict\Tests\Integration;

use Jbrada\Semverdict\Cli\NextCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

class NextCommandTest extends TestCase
{
    private string $workDir;
    private string $fixtures;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/semverdict-next-test-' . uniqid();
        mkdir($this->workDir, 0777, true);
        $this->fixtures = dirname(__DIR__) . '/fixtures';
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->workDir));
    }

    public function testSuggestsNextMinorTagForAddedPublicMethod(): void
    {
        $repo = $this->taggedRepo('v1.0.0');
        copy($this->fixtures . '/module-v2-minor/src/Greeter.php', $repo . '/Greeter.php');

        $tester = new CommandTester(new NextCommand());
        $exitCode = $tester->execute(['path' => $repo], ['capture_stderr_separately' => true]);

        self::assertSame(NextCommand::EXIT_OK, $exitCode);
        self::assertStringContainsString('Baseline: v1.0.0', $tester->getDisplay());
        self::assertStringContainsString('Required bump: MINOR', $tester->getDisplay());
        self::assertStringContainsString('Suggested next tag: v1.1.0', $tester->getDisplay());
    }

    public function testJsonOutput(): void
    {
        $repo = $this->taggedRepo('1.0.0');
        copy($this->fixtures . '/module-v2-major/src/Greeter.php', $repo . '/Greeter.php');

        $tester = new CommandTester(new NextCommand());
        $exitCode = $tester->execute(['path' => $repo, '--json' => true], ['capture_stderr_separately' => true]);

        self::assertSame(NextCommand::EXIT_OK, $exitCode);
        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('1.0.0', $payload['baselineTag']);
        self::assertSame('major', $payload['requiredLevel']);
        self::assertSame('2.0.0', $payload['suggestedTag']);
        self::assertNotEmpty($payload['changes']);
    }

    public function testUnchangedWorkingTreeNeedsNoRelease(): void
    {
        $repo = $this->taggedRepo('v1.0.0');

        $tester = new CommandTester(new NextCommand());
        $exitCode = $tester->execute(['path' => $repo], ['capture_stderr_separately' => true]);

        self::assertSame(NextCommand::EXIT_OK, $exitCode);
        self::assertStringContainsString('No release needed', $tester->getDisplay());
    }

    public function testMissingTagsIsFatal(): void
    {
        $repo = $this->workDir . '/untagged';
        mkdir($repo, 0777, true);
        copy($this->fixtures . '/module-v1/src/Greeter.php', $repo . '/Greeter.php');
        $this->git($repo, 'init', '-q');
        $this->git($repo, 'add', '-A');
        $this->git($repo, 'commit', '-qm', 'initial');

        $tester = new CommandTester(new NextCommand());
        $exitCode = $tester->execute(['path' => $repo]);

        self::assertSame(NextCommand::EXIT_FATAL, $exitCode);
        self::assertStringContainsString('No semver tags found', $tester->getDisplay());
    }

    public function testExplicitBaselineTagMustExist(): void
    {
        $repo = $this->taggedRepo('v1.0.0');

        $tester = new CommandTester(new NextCommand());
        $exitCode = $tester->execute(['path' => $repo, '--tag' => 'v9.9.9']);

        self::assertSame(NextCommand::EXIT_FATAL, $exitCode);
        self::assertStringContainsString('Tag not found', $tester->getDisplay());
    }

    /**
     * Creates a repo whose $tag release is the module-v1 fixture.
     */
    private function taggedRepo(string $tag): string
    {
        $repo = $this->workDir . '/repo-' . $tag;
        mkdir($repo, 0777, true);
        copy($this->fixtures . '/module-v1/src/Greeter.php', $repo . '/Greeter.php');
        $this->git($repo, 'init', '-q');
        $this->git($repo, 'add', '-A');
        $this->git($repo, 'commit', '-qm', 'release');
        $this->git($repo, 'tag', $tag);

        return $repo;
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
