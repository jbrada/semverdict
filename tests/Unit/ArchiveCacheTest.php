<?php

declare(strict_types=1);

namespace Jbrada\Semverdict\Tests\Unit;

use Jbrada\Semverdict\Archive\ArchiveCache;
use Jbrada\Semverdict\Archive\ArchiveException;
use Jbrada\Semverdict\Repository\Release;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class ArchiveCacheTest extends TestCase
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

    public function testExtractsFlatZip(): void
    {
        $zipPath = $this->makeZip(['registration.php' => '<?php // flat']);
        $cache = $this->cacheServing($zipPath);

        $root = $cache->getSourceDir('acme/demo', $this->release('1.0.0'));

        self::assertFileExists($root . '/registration.php');
    }

    public function testStripsSingleWrapperDirectory(): void
    {
        $zipPath = $this->makeZip(['acme-demo-abc123/registration.php' => '<?php // wrapped']);
        $cache = $this->cacheServing($zipPath);

        $root = $cache->getSourceDir('acme/demo', $this->release('1.0.0'));

        self::assertStringEndsWith('/acme-demo-abc123', $root);
        self::assertFileExists($root . '/registration.php');
    }

    public function testCacheHitSkipsDownload(): void
    {
        $zipPath = $this->makeZip(['registration.php' => '<?php']);
        $downloads = 0;
        $cache = new ArchiveCache(
            $this->workDir . '/cache',
            httpDownload: function (string $url, string $dest) use ($zipPath, &$downloads): void {
                $downloads++;
                copy($zipPath, $dest);
            },
        );

        $first = $cache->getSourceDir('acme/demo', $this->release('1.0.0'));
        $second = $cache->getSourceDir('acme/demo', $this->release('1.0.0'));

        self::assertSame($first, $second);
        self::assertSame(1, $downloads);
    }

    public function testIncompleteCacheEntryIsRefetched(): void
    {
        $zipPath = $this->makeZip(['registration.php' => '<?php']);
        $cache = $this->cacheServing($zipPath);
        $release = $this->release('1.0.0');

        // Simulate an interrupted extract: directory exists but no .complete marker.
        $staleDir = $this->workDir . '/cache/acme/demo/' . $release->normalized;
        mkdir($staleDir . '/src', 0777, true);
        file_put_contents($staleDir . '/src/half-written.php', '<?php');

        $root = $cache->getSourceDir('acme/demo', $release);

        self::assertFileExists($root . '/registration.php');
        self::assertFileDoesNotExist($root . '/half-written.php');
    }

    public function testCorruptMarkerTriggersRefetch(): void
    {
        $zipPath = $this->makeZip(['registration.php' => '<?php']);
        $downloads = 0;
        $cache = new ArchiveCache(
            $this->workDir . '/cache',
            httpDownload: function (string $url, string $dest) use ($zipPath, &$downloads): void {
                $downloads++;
                copy($zipPath, $dest);
            },
        );
        $release = $this->release('1.0.0');

        $root = $cache->getSourceDir('acme/demo', $release);
        // An empty marker must not be trusted (it would resolve to the release dir itself).
        file_put_contents(dirname($root) . '/.complete', '');

        $again = $cache->getSourceDir('acme/demo', $release);

        self::assertSame(2, $downloads);
        self::assertSame($root, $again);
        self::assertFileExists($again . '/registration.php');
    }

    public function testMarkerPointingOutsideLayoutIsRejected(): void
    {
        $zipPath = $this->makeZip(['registration.php' => '<?php']);
        $cache = $this->cacheServing($zipPath);
        $release = $this->release('1.0.0');

        $root = $cache->getSourceDir('acme/demo', $release);
        file_put_contents(dirname($root) . '/.complete', 'src/..');

        $again = $cache->getSourceDir('acme/demo', $release);

        self::assertSame($root, $again);
    }

    public function testRejectsInvalidPackageName(): void
    {
        $cache = $this->cacheServing('/nonexistent');

        $this->expectException(ArchiveException::class);
        $this->expectExceptionMessage('Invalid package name');
        $cache->getSourceDir('../../etc', $this->release('1.0.0'));
    }

    public function testFlattensUnsafeVersionDirectoryNames(): void
    {
        $zipPath = $this->makeZip(['registration.php' => '<?php']);
        $cache = $this->cacheServing($zipPath);
        $release = new Release('dev-feature/x', 'dev-feature/x', 'https://example.test/dev.zip', 'zip', null);

        $root = $cache->getSourceDir('acme/demo', $release);

        self::assertStringContainsString('/cache/acme/demo/dev-feature_x/', $root);
        self::assertFileExists($root . '/registration.php');
    }

    public function testFailsWithoutZipDist(): void
    {
        $cache = $this->cacheServing('/nonexistent');
        $release = new Release('1.0.0', '1.0.0.0', null, null, null);

        $this->expectException(ArchiveException::class);
        $cache->getSourceDir('acme/demo', $release);
    }

    private function cacheServing(string $zipPath): ArchiveCache
    {
        return new ArchiveCache(
            $this->workDir . '/cache',
            httpDownload: function (string $url, string $dest) use ($zipPath): void {
                if (!copy($zipPath, $dest)) {
                    throw new \RuntimeException('copy failed');
                }
            },
        );
    }

    /**
     * @param array<string, string> $files path-in-zip => contents
     */
    private function makeZip(array $files): string
    {
        $zipPath = $this->workDir . '/fixture-' . uniqid() . '.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);
        foreach ($files as $path => $contents) {
            $zip->addFromString($path, $contents);
        }
        $zip->close();

        return $zipPath;
    }

    private function release(string $version): Release
    {
        return new Release($version, $version . '.0', 'https://example.test/' . $version . '.zip', 'zip', null);
    }
}
