<?php

declare(strict_types=1);

namespace Jbrada\Semverdict\Audit;

final class PairResult
{
    /**
     * @param array<int, array{context: string, level: string, location: string, target: string, reason: string, code: string}> $changes
     */
    public function __construct(
        public readonly string $fromVersion,
        public readonly string $toVersion,
        public readonly BumpLevel $actual,
        public readonly ?BumpLevel $required,
        public readonly Verdict $verdict,
        public readonly array $changes = [],
        public readonly ?string $error = null,
    ) {
    }
}
