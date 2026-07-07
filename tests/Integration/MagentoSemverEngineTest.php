<?php

declare(strict_types=1);

namespace Jbrada\Semverdict\Tests\Integration;

use Jbrada\Semverdict\Audit\BumpLevel;
use Jbrada\Semverdict\Engine\MagentoSemverEngine;
use PHPUnit\Framework\TestCase;

class MagentoSemverEngineTest extends TestCase
{
    private MagentoSemverEngine $engine;
    private string $fixtures;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $this->engine = new MagentoSemverEngine(
            workerPath: $root . '/bin/analyze-pair',
            includesPath: $root . '/resources/module_includes.txt',
            excludesPath: $root . '/resources/module_excludes.txt',
        );
        $this->fixtures = dirname(__DIR__) . '/fixtures';
    }

    public function testAddedPublicMethodRequiresMinor(): void
    {
        $result = $this->engine->compare(
            $this->fixtures . '/module-v1/src',
            $this->fixtures . '/module-v2-minor/src',
        );

        self::assertFalse($result->failed);
        self::assertSame(BumpLevel::Minor, $result->requiredLevel);
        self::assertNotEmpty($result->changes, 'empty include patterns must still analyze files');
    }

    public function testChangedPublicSignatureRequiresMajor(): void
    {
        $result = $this->engine->compare(
            $this->fixtures . '/module-v1/src',
            $this->fixtures . '/module-v2-major/src',
        );

        self::assertFalse($result->failed);
        self::assertSame(BumpLevel::Major, $result->requiredLevel);
    }

    public function testIdenticalTreesRequireNothing(): void
    {
        $result = $this->engine->compare(
            $this->fixtures . '/module-v1/src',
            $this->fixtures . '/module-v1/src',
        );

        self::assertFalse($result->failed);
        self::assertSame(BumpLevel::None, $result->requiredLevel);
    }

    public function testNonApiSignatureChangeIsPatchUnderMagentoPolicy(): void
    {
        $result = $this->engine->compare(
            $this->fixtures . '/module-noapi-v1/src',
            $this->fixtures . '/module-noapi-v2/src',
        );

        self::assertFalse($result->failed);
        self::assertSame(BumpLevel::Patch, $result->requiredLevel);
    }

    public function testNonApiSignatureChangeIsMajorUnderStrictPolicy(): void
    {
        $strictEngine = $this->strictEngine();

        $result = $strictEngine->compare(
            $this->fixtures . '/module-noapi-v1/src',
            $this->fixtures . '/module-noapi-v2/src',
        );

        self::assertFalse($result->failed);
        self::assertSame(BumpLevel::Major, $result->requiredLevel);
        self::assertSame('magento-semver+strict-php', $strictEngine->name());

        // Identical trees must stay NONE (no false positives from the strict analyzer).
        $identical = $strictEngine->compare(
            $this->fixtures . '/module-noapi-v1/src',
            $this->fixtures . '/module-noapi-v1/src',
        );
        self::assertSame(BumpLevel::None, $identical->requiredLevel);
    }

    public function testStrictPolicyWithPhpOnlyReportTypesSkipsMagentoAnalyzers(): void
    {
        $strictEngine = $this->strictEngine();

        // ReportBuilder treats an empty analyzer list as "run everything"; requesting
        // only the api surface under strict policy must not fall into that trap.
        $result = $strictEngine->compare(
            $this->fixtures . '/module-noapi-v1/src',
            $this->fixtures . '/module-noapi-v2/src',
            ['api'],
        );

        self::assertFalse($result->failed);
        self::assertSame(BumpLevel::Major, $result->requiredLevel);
        foreach ($result->changes as $change) {
            self::assertSame('class', $change['context'], 'only PHP contexts may be reported');
        }
    }

    public function testStrictPolicyWithXmlOnlyReportTypesSkipsPhpAnalysis(): void
    {
        $result = $this->strictEngine()->compare(
            $this->fixtures . '/module-noapi-v1/src',
            $this->fixtures . '/module-noapi-v2/src',
            ['dbSchema'],
        );

        self::assertFalse($result->failed);
        // FileChangeDetector still requires PATCH for any changed file, but the
        // MAJOR-level PHP signature break must not be analyzed.
        self::assertSame(BumpLevel::Patch, $result->requiredLevel, 'PHP signature changes must not leak into an XML-only report');
        self::assertSame([], $result->changes);
    }

    public function testMissingDirectoryFailsGracefully(): void
    {
        $result = $this->engine->compare($this->fixtures . '/nope', $this->fixtures . '/module-v1/src');

        self::assertTrue($result->failed);
        self::assertNotNull($result->error);
    }

    private function strictEngine(): MagentoSemverEngine
    {
        $root = dirname(__DIR__, 2);

        return new MagentoSemverEngine(
            workerPath: $root . '/bin/analyze-pair',
            includesPath: $root . '/resources/module_includes.txt',
            excludesPath: $root . '/resources/module_excludes.txt',
            policy: 'strict',
        );
    }
}
