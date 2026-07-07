<?php

declare(strict_types=1);

namespace Jbrada\Semverdict\Archive;

use Jbrada\Semverdict\Repository\Release;
use Jbrada\Semverdict\Support\Dir;
use Jbrada\Semverdict\Support\Http;
use Jbrada\Semverdict\Support\PackageName;
use ZipArchive;

class ArchiveCache
{
    private const MARKER_FILE = '.complete';

    /** @var callable(string, string, ?string): void */
    private $httpDownload;

    public function __construct(
        private readonly string $cacheDir,
        private readonly ?string $basicAuth = null,
        ?callable $httpDownload = null,
    ) {
        $this->httpDownload = $httpDownload ?? Http::download(...);
    }

    /**
     * Downloads and extracts a release (cached), returning the module root directory.
     *
     * @throws ArchiveException
     */
    public function getSourceDir(string $packageName, Release $release): string
    {
        if (!$release->hasZipDist()) {
            throw new ArchiveException("Release {$release->version} has no zip dist.");
        }
        if (!PackageName::isValid($packageName)) {
            throw new ArchiveException("Invalid package name: {$packageName}");
        }

        $releaseDir = $this->cacheDir . '/' . $packageName . '/' . $this->versionDirName($release->normalized);
        $markerPath = $releaseDir . '/' . self::MARKER_FILE;

        if (is_file($markerPath)) {
            $relativeRoot = trim((string) file_get_contents($markerPath));
            $root = $releaseDir . '/' . $relativeRoot;
            // Only trust a marker that points at the extraction layout this class
            // writes; anything else (empty, corrupted) means refetch.
            if (preg_match('#^src(/[^/]+)?$#', $relativeRoot) === 1
                && !in_array(basename($relativeRoot), ['.', '..'], true)
                && is_dir($root)
            ) {
                return $root;
            }
        }

        // Missing or incomplete: wipe and fetch fresh.
        Dir::remove($releaseDir);
        if (!mkdir($releaseDir, 0777, true) && !is_dir($releaseDir)) {
            throw new ArchiveException("Cannot create cache directory {$releaseDir}.");
        }

        $zipPath = $releaseDir . '/dist.zip';
        $extractDir = $releaseDir . '/src';

        try {
            ($this->httpDownload)($release->distUrl, $zipPath, $this->basicAuth);
        } catch (\RuntimeException $e) {
            throw new ArchiveException("Download failed for {$release->version}: {$e->getMessage()}", previous: $e);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new ArchiveException("Cannot open zip archive for {$release->version}.");
        }
        if (!mkdir($extractDir, 0777, true) && !is_dir($extractDir)) {
            $zip->close();
            throw new ArchiveException("Cannot create extraction directory {$extractDir}.");
        }
        if (!$zip->extractTo($extractDir)) {
            $zip->close();
            throw new ArchiveException("Cannot extract zip archive for {$release->version}.");
        }
        $zip->close();
        unlink($zipPath);

        $relativeRoot = 'src' . $this->detectSingleRootSuffix($extractDir);
        $root = $releaseDir . '/' . $relativeRoot;
        if ($this->isEmptyDir($root)) {
            throw new ArchiveException("Archive for {$release->version} extracted to an empty directory.");
        }

        if (file_put_contents($markerPath, $relativeRoot) === false) {
            throw new ArchiveException("Cannot write cache marker {$markerPath}.");
        }

        return $root;
    }

    /**
     * Normalized versions are already filesystem-friendly ("1.2.3.0"), but branch
     * versions may contain slashes ("dev-feature/x") — flatten anything unsafe.
     */
    private function versionDirName(string $normalized): string
    {
        $name = preg_replace('#[^A-Za-z0-9._+~-]#', '_', $normalized) ?? '';
        if ($name === '' || $name === '.' || $name === '..') {
            return 'v_' . sha1($normalized);
        }

        return $name;
    }

    /**
     * GitHub codeload zips wrap the module in a single "vendor-package-<sha>/" directory;
     * repo.magento.com zips are flat. Returns "/<dir>" when a single wrapper dir is found.
     */
    private function detectSingleRootSuffix(string $extractDir): string
    {
        $entries = array_values(array_diff(scandir($extractDir), ['.', '..']));
        if (count($entries) === 1 && is_dir($extractDir . '/' . $entries[0])) {
            return '/' . $entries[0];
        }

        return '';
    }

    private function isEmptyDir(string $dir): bool
    {
        if (!is_dir($dir)) {
            return true;
        }

        return array_diff(scandir($dir), ['.', '..']) === [];
    }
}
