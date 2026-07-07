<?php

declare(strict_types=1);

namespace Jbrada\Semverdict\Engine;

use Jbrada\Semverdict\Audit\BumpLevel;

final class AnalysisResult
{
    /**
     * @param array<int, array{context: string, level: string, location: string, target: string, reason: string, code: string}> $changes
     */
    private function __construct(
        public readonly ?BumpLevel $requiredLevel,
        public readonly array $changes,
        public readonly bool $failed,
        public readonly ?string $error,
    ) {
    }

    /**
     * @param array<int, array{context: string, level: string, location: string, target: string, reason: string, code: string}> $changes
     */
    public static function success(BumpLevel $requiredLevel, array $changes): self
    {
        return new self($requiredLevel, $changes, false, null);
    }

    public static function failure(string $error): self
    {
        return new self(null, [], true, $error);
    }
}
