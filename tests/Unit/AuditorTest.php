<?php

declare(strict_types=1);

namespace Jbrada\Semverdict\Tests\Unit;

use Jbrada\Semverdict\Archive\ArchiveCache;
use Jbrada\Semverdict\Audit\AuditOptions;
use Jbrada\Semverdict\Audit\Auditor;
use Jbrada\Semverdict\Audit\BumpLevel;
use Jbrada\Semverdict\Audit\Verdict;
use Jbrada\Semverdict\Engine\AnalysisResult;
use Jbrada\Semverdict\Engine\EngineInterface;
use Jbrada\Semverdict\Repository\RepositoryClient;
use Jbrada\Semverdict\Repository\RepositoryException;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class AuditorTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/semverdict-test-' . uniqid();
        mkdir($this->workDir, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->workDir));
    }

    public function testVerdictsAcrossHistory(): void
    {
        // History: 1.0.0 -> 1.0.1 (patch made, patch required: OK)
        //          1.0.1 -> 1.1.0 (minor made, none required: OVER)
        //          1.1.0 -> 1.1.1 (patch made, MAJOR required: VIOLATION)
        $auditor = $this->auditor(
            versions: ['1.0.0', '1.0.1', '1.1.0', '1.1.1'],
            requiredLevels: [BumpLevel::Patch, BumpLevel::None, BumpLevel::Major],
        );

        $report = $auditor->audit('acme/demo', new AuditOptions());

        $verdicts = array_map(fn ($p) => $p->verdict, $report->pairs);
        self::assertSame([Verdict::Ok, Verdict::Over, Verdict::Violation], $verdicts);
        self::assertFalse($report->followsSemver());
        self::assertSame(1, $report->summary()['violations']);
    }

    public function testZeroDotXUnderBumpIsExemptUnlessStrict(): void
    {
        $lenient = $this->auditor(['0.1.0', '0.1.1'], [BumpLevel::Major]);
        $report = $lenient->audit('acme/demo', new AuditOptions());
        self::assertSame(Verdict::ZeroX, $report->pairs[0]->verdict);
        self::assertTrue($report->followsSemver());

        $strict = $this->auditor(['0.1.0', '0.1.1'], [BumpLevel::Major]);
        $report = $strict->audit('acme/demo', new AuditOptions(strict: true));
        self::assertSame(Verdict::Violation, $report->pairs[0]->verdict);
        self::assertFalse($report->followsSemver());
    }

    public function testFailedAnalysisDoesNotAbortAudit(): void
    {
        $auditor = $this->auditor(
            versions: ['1.0.0', '1.0.1', '1.0.2'],
            requiredLevels: [null, BumpLevel::Patch], // null => engine failure
        );

        $report = $auditor->audit('acme/demo', new AuditOptions());

        self::assertSame(Verdict::Failed, $report->pairs[0]->verdict);
        self::assertSame(Verdict::Ok, $report->pairs[1]->verdict);
        self::assertTrue($report->hasFailures());
        self::assertTrue($report->followsSemver());
    }

    public function testPrereleasesAndDevVersionsAreSkippedByDefault(): void
    {
        $auditor = $this->auditor(
            versions: ['1.0.0', '1.1.0-beta1', '1.1.0', 'dev-main'],
            requiredLevels: [BumpLevel::Minor],
        );

        $report = $auditor->audit('acme/demo', new AuditOptions());

        self::assertCount(1, $report->pairs);
        self::assertSame('1.0.0', $report->pairs[0]->fromVersion);
        self::assertSame('1.1.0', $report->pairs[0]->toVersion);
        self::assertCount(2, $report->skippedReleases);
    }

    public function testLimitAppliesToMostRecentPairs(): void
    {
        $auditor = $this->auditor(
            versions: ['1.0.0', '1.1.0', '1.2.0', '1.3.0'],
            requiredLevels: [BumpLevel::Minor, BumpLevel::Minor, BumpLevel::Minor],
        );

        $report = $auditor->audit('acme/demo', new AuditOptions(limit: 2));

        self::assertCount(2, $report->pairs);
        self::assertSame('1.1.0', $report->pairs[0]->fromVersion);
        self::assertSame('1.3.0', $report->pairs[1]->toVersion);
    }

    public function testFewerThanTwoReleasesIsFatal(): void
    {
        $auditor = $this->auditor(['1.0.0'], []);

        $this->expectException(RepositoryException::class);
        $auditor->audit('acme/demo', new AuditOptions());
    }

    /**
     * @param string[] $versions
     * @param array<int, BumpLevel|null> $requiredLevels one per consecutive pair; null simulates engine failure
     */
    private function auditor(array $versions, array $requiredLevels): Auditor
    {
        $parser = new \Composer\Semver\VersionParser();
        $entries = [];
        foreach ($versions as $version) {
            $entries[] = [
                'version' => $version,
                'version_normalized' => $parser->normalize($version),
                'dist' => ['url' => "https://example.test/{$version}.zip", 'type' => 'zip'],
            ];
        }

        $repository = new RepositoryClient(
            httpGet: fn () => json_encode(['packages' => ['acme/demo' => $entries]]),
        );

        $zipPath = $this->workDir . '/module.zip';
        if (!is_file($zipPath)) {
            $zip = new ZipArchive();
            $zip->open($zipPath, ZipArchive::CREATE);
            $zip->addFromString('registration.php', '<?php');
            $zip->close();
        }
        $cache = new ArchiveCache(
            $this->workDir . '/cache',
            httpDownload: function (string $url, string $dest) use ($zipPath): void {
                copy($zipPath, $dest);
            },
        );

        return new Auditor($repository, $cache, new FakeEngine($requiredLevels));
    }
}

class FakeEngine implements EngineInterface
{
    private int $call = 0;

    /**
     * @param array<int, BumpLevel|null> $requiredLevels
     */
    public function __construct(private readonly array $requiredLevels)
    {
    }

    public function compare(string $beforeDir, string $afterDir, array $reportTypes = []): AnalysisResult
    {
        $index = $this->call++;
        $level = array_key_exists($index, $this->requiredLevels) ? $this->requiredLevels[$index] : BumpLevel::None;

        return $level === null
            ? AnalysisResult::failure('simulated engine failure')
            : AnalysisResult::success($level, []);
    }

    public function name(): string
    {
        return 'fake';
    }
}
