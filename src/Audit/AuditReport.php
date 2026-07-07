<?php

declare(strict_types=1);

namespace Jbrada\Semverdict\Audit;

final class AuditReport
{
    /**
     * @param PairResult[] $pairs
     * @param string[] $skippedReleases versions excluded up front (no zip dist, prerelease, dev)
     */
    public function __construct(
        public readonly string $package,
        public readonly string $engine,
        public readonly AuditOptions $options,
        public readonly array $pairs,
        public readonly array $skippedReleases = [],
    ) {
    }

    /**
     * @return array{pairs: int, ok: int, over: int, violations: int, zeroXExempt: int, failed: int}
     */
    public function summary(): array
    {
        $counts = ['pairs' => count($this->pairs), 'ok' => 0, 'over' => 0, 'violations' => 0, 'zeroXExempt' => 0, 'failed' => 0];
        foreach ($this->pairs as $pair) {
            match ($pair->verdict) {
                Verdict::Ok => $counts['ok']++,
                Verdict::Over => $counts['over']++,
                Verdict::Violation => $counts['violations']++,
                Verdict::ZeroX => $counts['zeroXExempt']++,
                Verdict::Failed => $counts['failed']++,
            };
        }

        return $counts;
    }

    public function followsSemver(): bool
    {
        return $this->summary()['violations'] === 0;
    }

    public function hasFailures(): bool
    {
        return $this->summary()['failed'] > 0;
    }
}
