<?php

declare(strict_types=1);

namespace Jbrada\Semverdict\Repository;

final class Release
{
    public function __construct(
        public readonly string $version,
        public readonly string $normalized,
        public readonly ?string $distUrl,
        public readonly ?string $distType,
        public readonly ?string $time,
    ) {
    }

    public function hasZipDist(): bool
    {
        return $this->distUrl !== null && $this->distType === 'zip';
    }
}
