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
        ], JSON_THROW_ON_ERROR);

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
        ], JSON_THROW_ON_ERROR);

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
            ++$requests;

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
        $client = new RepositoryClient(httpGet: fn () => json_encode(['packages' => []], JSON_THROW_ON_ERROR));

        $this->expectException(RepositoryException::class);
        $client->getReleases('acme/demo');
    }

    public function testFallsBackToV1PackagesJsonWhenV2IsUnavailable(): void
    {
        // Some vendor repositories serve v1 only: /p2/ 403s and packages.json
        // carries the definitions inline, without version_normalized.
        $requested = [];
        $client = new RepositoryClient(
            'https://composer.example.com/community',
            httpGet: function (string $url) use (&$requested): string {
                $requested[] = $url;
                if (str_contains($url, '/p2/')) {
                    throw new \RuntimeException('HTTP request failed (HTTP/1.1 403 Forbidden)');
                }

                return json_encode([
                    'packages' => [
                        'acme/demo' => [
                            '1.0.0' => ['name' => 'acme/demo', 'version' => '1.0.0'],
                            '1.1.0' => ['name' => 'acme/demo', 'version' => '1.1.0'],
                            // Not a version Composer can parse — must be dropped
                            // rather than blow up the whole audit.
                            'nonsense' => ['name' => 'acme/demo', 'version' => 'whatever this is'],
                        ],
                    ],
                ], JSON_THROW_ON_ERROR);
            },
        );

        $releases = $client->getReleases('acme/demo');

        self::assertSame(['1.0.0', '1.1.0'], array_map(fn ($release) => $release->version, $releases));
        self::assertSame('1.0.0.0', $releases[0]->normalized, 'v1 metadata must get a derived normalized version');
        self::assertSame([
            'https://composer.example.com/community/p2/acme/demo.json',
            'https://composer.example.com/community/packages.json',
        ], $requested);
    }

    public function testFollowsV1Includes(): void
    {
        $client = new RepositoryClient(
            'https://composer.example.com',
            httpGet: function (string $url): string {
                if (str_contains($url, '/p2/')) {
                    throw new \RuntimeException('HTTP request failed (HTTP/1.1 404 Not Found)');
                }
                if (str_ends_with($url, '/packages.json')) {
                    return json_encode(['includes' => ['include/all$abc.json' => ['sha1' => 'abc']]], JSON_THROW_ON_ERROR);
                }

                return json_encode([
                    'packages' => ['acme/demo' => ['2.0.0' => ['name' => 'acme/demo', 'version' => '2.0.0']]],
                ], JSON_THROW_ON_ERROR);
            },
        );

        $releases = $client->getReleases('acme/demo');

        self::assertCount(1, $releases);
        self::assertSame('2.0.0', $releases[0]->version);
    }

    public function testReportsTheV2ErrorWhenBothProtocolsFail(): void
    {
        $client = new RepositoryClient(
            'https://composer.example.com',
            httpGet: function (string $url): string {
                throw new \RuntimeException(str_contains($url, '/p2/') ? 'v2 says 403' : 'v1 says 401');
            },
        );

        try {
            $client->getReleases('acme/demo');
            self::fail('expected a RepositoryException');
        } catch (RepositoryException $e) {
            self::assertStringContainsString('v2 says 403', $e->getMessage());
        }
    }
}
