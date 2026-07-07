<?php

declare(strict_types=1);

namespace Jbrada\Semverdict\Audit;

enum BumpLevel: int
{
    case None = 0;
    case Patch = 1;
    case Minor = 2;
    case Major = 3;

    public function isAtLeast(self $other): bool
    {
        return $this->value >= $other->value;
    }

    public function label(): string
    {
        return strtoupper($this->name);
    }
}
