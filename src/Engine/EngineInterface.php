<?php

declare(strict_types=1);

namespace Jbrada\Semverdict\Engine;

interface EngineInterface
{
    /**
     * Compares two extracted source trees and reports the minimum required version bump.
     * Implementations must not throw; failures are returned as a failed AnalysisResult.
     *
     * @param string[] $reportTypes magento-semver report types; empty means all
     */
    public function compare(string $beforeDir, string $afterDir, array $reportTypes = []): AnalysisResult;

    public function name(): string;
}
