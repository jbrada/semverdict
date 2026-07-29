<?php

declare(strict_types=1);

namespace Jbrada\Semverdict\Tests\Unit;

use Jbrada\Semverdict\Project\ComposerProject;
use Jbrada\Semverdict\Project\ProjectException;
use PHPUnit\Framework\TestCase;

class ComposerProjectTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/semverdict-project-' . uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function testMissingComposerJsonThrows(): void
    {
        $this->expectException(ProjectException::class);
        new ComposerProject($this->dir);
    }

    public function testDirectRequiresFiltersPlatformAndMagento(): void
    {
        $this->writeComposerJson([
            'require' => [
                'php' => '~8.3.0',
                'ext-intl' => '*',
                'magento/product-community-edition' => '2.4.8-p3',
                'amasty/preorder' => '^2.0',
                'Deployer/Deployer' => '^7.0',
            ],
        ]);
        $project = new ComposerProject($this->dir);

        self::assertSame(['amasty/preorder', 'deployer/deployer'], $project->directRequires());
        self::assertContains('magento/product-community-edition', $project->directRequires(includeMagento: true));
    }

    public function testRepositoriesListFormKeepsOrderAndAppendsPackagist(): void
    {
        $this->writeComposerJson([
            'repositories' => [
                ['type' => 'composer', 'url' => 'https://composer.amasty.com/community/'],
                ['type' => 'composer', 'url' => 'https://repo.magento.com'],
                ['type' => 'vcs', 'url' => 'https://github.com/foo/bar'],
            ],
        ]);

        self::assertSame([
            'https://composer.amasty.com/community',
            'https://repo.magento.com',
            ComposerProject::PACKAGIST,
        ], (new ComposerProject($this->dir))->repositories());
    }

    public function testRepositoriesMapFormWithPackagistDisabled(): void
    {
        $this->writeComposerJson([
            'repositories' => [
                'amasty' => ['type' => 'composer', 'url' => 'https://composer.amasty.com/enterprise'],
                'packagist.org' => false,
            ],
        ]);

        self::assertSame(
            ['https://composer.amasty.com/enterprise'],
            (new ComposerProject($this->dir))->repositories(),
        );
    }

    public function testAuthForMatchesHostFromAuthJson(): void
    {
        $this->writeComposerJson([]);
        file_put_contents($this->dir . '/auth.json', (string) json_encode([
            'http-basic' => [
                'composer.amasty.com' => ['username' => 'u', 'password' => 'p'],
            ],
        ]));
        $project = new ComposerProject($this->dir);

        self::assertSame('u:p', $project->authFor('https://composer.amasty.com/enterprise'));
        self::assertNull($project->authFor('https://repo.magento.com'));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeComposerJson(array $data): void
    {
        file_put_contents($this->dir . '/composer.json', (string) json_encode($data));
    }
}
