<?php

declare(strict_types=1);

namespace Jbrada\Semverdict\Tests\Unit;

use Jbrada\Semverdict\Audit\BumpClassifier;
use Jbrada\Semverdict\Audit\BumpLevel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BumpClassifierTest extends TestCase
{
    #[DataProvider('bumpProvider')]
    public function testClassifyActual(string $from, string $to, BumpLevel $expected): void
    {
        self::assertSame($expected, (new BumpClassifier())->classifyActual($from, $to));
    }

    public static function bumpProvider(): array
    {
        return [
            'major' => ['1.2.3.0', '2.0.0.0', BumpLevel::Major],
            'minor' => ['1.2.3.0', '1.3.0.0', BumpLevel::Minor],
            'patch' => ['1.2.3.0', '1.2.4.0', BumpLevel::Patch],
            'fourth component only' => ['1.2.3.0', '1.2.3.1', BumpLevel::Patch],
            'identical' => ['1.2.3.0', '1.2.3.0', BumpLevel::None],
            'prerelease to stable, same triple' => ['1.0.0.0-beta1', '1.0.0.0', BumpLevel::Patch],
            'zero major bump' => ['0.9.0.0', '1.0.0.0', BumpLevel::Major],
            'multi-digit' => ['3.9.0.0', '3.10.0.0', BumpLevel::Minor],
        ];
    }

    public function testIsZeroDotX(): void
    {
        $classifier = new BumpClassifier();
        self::assertTrue($classifier->isZeroDotX('0.14.3.0'));
        self::assertFalse($classifier->isZeroDotX('1.0.0.0'));
    }
}
