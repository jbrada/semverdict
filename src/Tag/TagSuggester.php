<?php

declare(strict_types=1);

namespace Jbrada\Semverdict\Tag;

use Jbrada\Semverdict\Audit\BumpLevel;

/**
 * Turns "baseline tag + required bump level" into the next tag to use,
 * preserving the baseline's "v" prefix style.
 */
final class TagSuggester
{
    /**
     * Accepts plain semver tags with an optional "v" prefix; extra numeric
     * components (Magento-style 1.2.3.4) are tolerated and ignored.
     */
    private const TAG_PATTERN = '/^(?<prefix>v)?(?<major>\d+)\.(?<minor>\d+)(?:\.(?<patch>\d+))?(?:\.\d+)*(?<pre>-[0-9A-Za-z.]+)?(?:\+[0-9A-Za-z.]+)?$/';

    public static function isSemverTag(string $tag): bool
    {
        return preg_match(self::TAG_PATTERN, $tag) === 1;
    }

    /**
     * @throws \InvalidArgumentException when the baseline tag is not semver-shaped
     */
    public function suggest(string $baselineTag, BumpLevel $required): TagSuggestion
    {
        if (preg_match(self::TAG_PATTERN, $baselineTag, $m) !== 1) {
            throw new \InvalidArgumentException("Cannot compute a next version from non-semver tag {$baselineTag}.");
        }
        $prefix = $m['prefix'];
        $major = (int) $m['major'];
        $minor = (int) $m['minor'];
        $patch = (int) ($m['patch'] ?? 0);
        $stable = "{$prefix}{$major}.{$minor}.{$patch}";

        if (($m['pre'] ?? '') !== '') {
            $note = $required === BumpLevel::None
                ? "The working tree is identical to {$baselineTag}; tagging {$stable} promotes it to stable as-is."
                : "{$baselineTag} is a pre-release: semver allows any change, even a breaking one, before the stable {$stable} ships.";

            return new TagSuggestion($stable, [$note]);
        }

        return match ($required) {
            BumpLevel::None => new TagSuggestion(
                null,
                ["The working tree is identical to {$baselineTag} on every analyzed surface — nothing to release."],
            ),
            BumpLevel::Patch => new TagSuggestion("{$prefix}{$major}.{$minor}." . ($patch + 1)),
            BumpLevel::Minor => new TagSuggestion("{$prefix}{$major}." . ($minor + 1) . '.0'),
            BumpLevel::Major => $major === 0
                ? new TagSuggestion("{$prefix}0." . ($minor + 1) . '.0', [
                    "Semver makes no promises below 1.0.0; bumping the 0.x minor is the convention for a break (composer's ^0.{$minor} constraints will not pull it in automatically).",
                    "Tag {$prefix}1.0.0 instead if the API is ready to stabilize.",
                ])
                : new TagSuggestion($prefix . ($major + 1) . '.0.0'),
        };
    }
}
