<?php

declare(strict_types=1);

namespace Jbrada\Semverdict\Tag;

final class TagSuggestion
{
    /**
     * @param string|null $tag next tag to use, in the baseline's prefix style; null when no release is needed
     * @param list<string> $notes caveats worth surfacing alongside the suggestion
     */
    public function __construct(
        public readonly ?string $tag,
        public readonly array $notes = [],
    ) {
    }
}
