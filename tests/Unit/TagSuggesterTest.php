<?php

declare(strict_types=1);

namespace Jbrada\Semverdict\Tests\Unit;

use Jbrada\Semverdict\Audit\BumpLevel;
use Jbrada\Semverdict\Tag\TagSuggester;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TagSuggesterTest extends TestCase
{
    /**
     * @return array<string, array{string, BumpLevel, ?string}>
     */
    public static function suggestions(): array
    {
        return [
            'patch keeps v prefix' => ['v1.2.3', BumpLevel::Patch, 'v1.2.4'],
            'minor resets patch' => ['v1.2.3', BumpLevel::Minor, 'v1.3.0'],
            'major resets minor and patch' => ['v1.2.3', BumpLevel::Major, 'v2.0.0'],
            'no release needed' => ['v1.2.3', BumpLevel::None, null],
            'prefix style is preserved' => ['1.2.3', BumpLevel::Minor, '1.3.0'],
            'two-part tag gets a full triple' => ['v1.2', BumpLevel::Patch, 'v1.2.1'],
            'four-part tag ignores the extra component' => ['1.2.3.4', BumpLevel::Patch, '1.2.4'],
            '0.x break bumps the minor' => ['v0.5.2', BumpLevel::Major, 'v0.6.0'],
            '0.x feature bumps the minor' => ['v0.5.2', BumpLevel::Minor, 'v0.6.0'],
            '0.x fix bumps the patch' => ['v0.5.2', BumpLevel::Patch, 'v0.5.3'],
            'pre-release promotes to its stable triple' => ['v1.3.0-beta1', BumpLevel::Major, 'v1.3.0'],
            'unchanged pre-release still promotes' => ['v1.3.0-beta1', BumpLevel::None, 'v1.3.0'],
        ];
    }

    #[DataProvider('suggestions')]
    public function testSuggest(string $baseline, BumpLevel $required, ?string $expected): void
    {
        $suggestion = (new TagSuggester())->suggest($baseline, $required);

        self::assertSame($expected, $suggestion->tag);
    }

    public function testZeroDotXBreakExplainsTheConvention(): void
    {
        $suggestion = (new TagSuggester())->suggest('v0.5.2', BumpLevel::Major);

        self::assertNotSame([], $suggestion->notes);
        self::assertStringContainsString('1.0.0', implode(' ', $suggestion->notes));
    }

    public function testNonSemverTagIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new TagSuggester())->suggest('release-2024', BumpLevel::Patch);
    }

    public function testIsSemverTag(): void
    {
        self::assertTrue(TagSuggester::isSemverTag('v1.2.3'));
        self::assertTrue(TagSuggester::isSemverTag('1.2'));
        self::assertTrue(TagSuggester::isSemverTag('1.2.3-rc.1'));
        self::assertFalse(TagSuggester::isSemverTag('release-2024'));
        self::assertFalse(TagSuggester::isSemverTag('20240101'));
    }
}
