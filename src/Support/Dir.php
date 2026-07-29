<?php

declare(strict_types=1);

namespace Jbrada\Semverdict\Support;

final class Dir
{
    /**
     * Recursively removes a directory; no-op when it does not exist.
     *
     * Returns false when anything survived — an interrupted earlier run can
     * leave read-only or partially written entries behind, and callers must be
     * able to report that instead of tripping over a half-removed directory.
     */
    public static function remove(string $dir): bool
    {
        if (!is_dir($dir)) {
            return true;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if (!$item instanceof \SplFileInfo) {
                continue;
            }
            $path = $item->getPathname();
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        clearstatcache();
        @rmdir($dir);
        clearstatcache();

        return !is_dir($dir);
    }
}
