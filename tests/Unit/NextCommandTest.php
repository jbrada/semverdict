<?php

declare(strict_types=1);

namespace Jbrada\Semverdict\Tests\Unit;

use Jbrada\Semverdict\Cli\NextCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class NextCommandTest extends TestCase
{
    public function testRejectsUnknownReportTypes(): void
    {
        $tester = new CommandTester(new NextCommand());

        $exitCode = $tester->execute(['--report-types' => 'api,nonsense']);

        self::assertSame(NextCommand::EXIT_FATAL, $exitCode);
        self::assertStringContainsString('Unknown report type(s): nonsense', $tester->getDisplay());
    }

    public function testRejectsUnknownPolicy(): void
    {
        $tester = new CommandTester(new NextCommand());

        $exitCode = $tester->execute(['--policy' => 'lenient']);

        self::assertSame(NextCommand::EXIT_FATAL, $exitCode);
        self::assertStringContainsString('Invalid --policy', $tester->getDisplay());
    }

    public function testRejectsMissingDirectory(): void
    {
        $tester = new CommandTester(new NextCommand());

        $exitCode = $tester->execute(['path' => sys_get_temp_dir() . '/semverdict-does-not-exist']);

        self::assertSame(NextCommand::EXIT_FATAL, $exitCode);
        self::assertStringContainsString('Not a directory', $tester->getDisplay());
    }

    public function testRejectsDirectoryOutsideAnyGitRepository(): void
    {
        $dir = sys_get_temp_dir() . '/semverdict-nogit-' . uniqid();
        mkdir($dir, 0777, true);
        try {
            $tester = new CommandTester(new NextCommand());

            $exitCode = $tester->execute(['path' => $dir]);

            self::assertSame(NextCommand::EXIT_FATAL, $exitCode);
            self::assertStringContainsString('git', strtolower($tester->getDisplay()));
        } finally {
            rmdir($dir);
        }
    }
}
