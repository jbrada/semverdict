<?php

declare(strict_types=1);

namespace Jbrada\Semverdict\Support;

final class PackageName
{
    /**
     * Composer's own vendor/package naming rule; also guarantees the name is
     * safe to embed in repository URLs and filesystem cache paths.
     */
    private const PATTERN = '#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$#';

    public static function isValid(string $packageName): bool
    {
        return preg_match(self::PATTERN, $packageName) === 1;
    }
}
