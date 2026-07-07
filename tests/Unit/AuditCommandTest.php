<?php

declare(strict_types=1);

namespace Jbrada\Semverdict\Tests\Unit;

use Jbrada\Semverdict\Cli\AuditCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class AuditCommandTest extends TestCase
{
    public function testRejectsInvalidPackageName(): void
    {
        $tester = new CommandTester(new AuditCommand());

        $exitCode = $tester->execute(['package' => 'not-a-package']);

        self::assertSame(AuditCommand::EXIT_FATAL, $exitCode);
        self::assertStringContainsString('Invalid package name', $tester->getDisplay());
    }

    public function testRejectsUnknownReportTypes(): void
    {
        $tester = new CommandTester(new AuditCommand());

        $exitCode = $tester->execute(['package' => 'acme/demo', '--report-types' => 'api,nonsense']);

        self::assertSame(AuditCommand::EXIT_FATAL, $exitCode);
        self::assertStringContainsString('Unknown report type(s): nonsense', $tester->getDisplay());
    }

    public function testRejectsUnknownPolicy(): void
    {
        $tester = new CommandTester(new AuditCommand());

        $exitCode = $tester->execute(['package' => 'acme/demo', '--policy' => 'lenient']);

        self::assertSame(AuditCommand::EXIT_FATAL, $exitCode);
        self::assertStringContainsString('Invalid --policy', $tester->getDisplay());
    }
}
