<?php

declare(strict_types=1);

namespace Jbrada\Semverdict\Audit;

enum Verdict: string
{
    /** Actual bump matches the required level exactly. */
    case Ok = 'OK';

    /** Bumped more than required — allowed by semver, reported as info. */
    case Over = 'OVER';

    /** Bumped less than required — a semver violation. */
    case Violation = 'VIOLATION';

    /** Under-bump within the 0.x range, exempt unless --strict (semver makes no promises below 1.0.0). */
    case ZeroX = 'ZERO_X';

    /** Download, extraction, or analysis failed for this pair. */
    case Failed = 'FAILED';
}
