<?php

declare(strict_types=1);

namespace Jbrada\Semverdict\Audit;

final class AuditOptions
{
    /**
     * @param string[] $reportTypes magento-semver report types; empty means all
     */
    public function __construct(
        public readonly bool $strict = false,
        public readonly bool $includePrereleases = false,
        public readonly ?int $limit = null,
        public readonly array $reportTypes = [],
    ) {
    }
}
