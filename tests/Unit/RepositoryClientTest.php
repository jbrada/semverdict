<?php

declare(strict_types=1);

namespace Jbrada\Semverdict\Tests\Unit;

use Jbrada\Semverdict\Repository\RepositoryClient;
use Jbrada\Semverdict\Repository\RepositoryException;
use PHPUnit\Framework\TestCase;

class RepositoryClientTest extends TestCase
{
    public function testExpandsMinifiedMetadataAndSortsAscending(): void
    {
        // Delta-minified p2 payload: later entries only carry changed fields.
        $body = json_encode([
            'packages' => [
                'acme/demo' => [
                    [
                        'version' => '2.0.0',
                        'version_normalized' => '2.0.0.0',
                        'dist' => ['url' => 'https://example.test/2.0.0.zip', 'type' => 'zip'],
                        'time' => '2024-03-01T00:00:00+00:00',
                    ],
                    [
                        'version' => '1.1.0',
                        'version_normalized' => '1.1.0.0',
                        'dist' => ['url' => 'https://example.test/1.1.0.zip', 'type' => 'zip'],
                        'time' => '2024-02-01T00:00:00+00:00',
                    ],
                    [
                        'version' => '1.0.0',
                        'version_normalized' => '1.0.0.0',
                        'dist' => ['url' => 'https://example.test/1.0.0.zip', 'type' => 'zip'],
                        'time' => '2024-01-01T00:00:00+00:00',
                    ],
                ],
            ],
            'minified' => 'composer/2.0',
        ]);

        $requestedUrls = [];
        $client = new RepositoryClient(
            httpGet: function (string $url) use ($body, &$requestedUrls): string {
                $requestedUrls[] = $url;

                return $body;
            },
        );

        $releases = $client->getReleases('acme/demo');

        self::assertSame(['https://repo.packagist.org/p2/acme/demo.json'], $requestedUrls);
        self::assertSame(['1.0.0', '1.1.0', '2.0.0'], array_map(fn ($r) => $r->version, $releases));
        self::assertSame('https://example.test/1.1.0.zip', $releases[1]->distUrl);
        self::assertTrue($releases[0]->hasZipDist());
    }

    public function testDeduplicatesByNormalizedVersion(): void
    {
        $body = json_encode([
            'packages' => [
                'acme/demo' => [
                    ['version' => 'v1.0.0', 'version_normalized' => '1.0.0.0', 'dist' => ['url' => 'a', 'type' => 'zip']],
                    ['version' => '1.0.0', 'version_normalized' => '1.0.0.0', 'dist' => ['url' => 'b', 'type' => 'zip']],
                ],
            ],
        ]);

        $client = new RepositoryClient(httpGet: fn () => $body);
        $releases = $client->getReleases('acme/demo');

        self::assertCount(1, $releases);
        self::assertSame('v1.0.0', $releases[0]->version);
    }

    public function testThrowsOnHttpFailure(): void
    {
        $client = new RepositoryClient(httpGet: function (): string {
            throw new \RuntimeException('HTTP request failed (404)');
        });

        $this->expectException(RepositoryException::class);
        $this->expectExceptionMessage('acme/missing');
        $client->getReleases('acme/missing');
    }

    public function testRejectsInvalidPackageName(): void
    {
        $requests = 0;
        $client = new RepositoryClient(httpGet: function () use (&$requests): string {
            $requests++;

            return '{}';
        });

        try {
            $client->getReleases('../../etc/passwd');
            self::fail('Expected RepositoryException');
        } catch (RepositoryException $e) {
            self::assertStringContainsString('Invalid package name', $e->getMessage());
        }
        self::assertSame(0, $requests, 'no request must be made for an invalid name');
    }

    public function testThrowsOnEmptyVersionList(): void
    {
        $client = new RepositoryClient(httpGet: fn () => json_encode(['packages' => []]));

        $this->expectException(RepositoryException::class);
        $client->getReleases('acme/demo');
    }
}
