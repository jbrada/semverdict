<?php

declare(strict_types=1);

namespace Jbrada\Semverdict\Audit;

class BumpClassifier
{
    /**
     * Classifies the actual version bump between two normalized versions (e.g. "1.2.3.0").
     */
    public function classifyActual(string $fromNormalized, string $toNormalized): BumpLevel
    {
        [$fromMajor, $fromMinor, $fromPatch] = $this->triple($fromNormalized);
        [$toMajor, $toMinor, $toPatch] = $this->triple($toNormalized);

        if ($fromMajor !== $toMajor) {
            return BumpLevel::Major;
        }
        if ($fromMinor !== $toMinor) {
            return BumpLevel::Minor;
        }
        if ($fromPatch !== $toPatch) {
            return BumpLevel::Patch;
        }

        // Identical major.minor.patch: differing 4th component or pre-release suffix.
        return $fromNormalized === $toNormalized ? BumpLevel::None : BumpLevel::Patch;
    }

    public function isZeroDotX(string $normalized): bool
    {
        return $this->triple($normalized)[0] === 0;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function triple(string $normalized): array
    {
        $numeric = explode('-', $normalized, 2)[0];
        $parts = explode('.', $numeric);

        return [(int) ($parts[0] ?? 0), (int) ($parts[1] ?? 0), (int) ($parts[2] ?? 0)];
    }
}
