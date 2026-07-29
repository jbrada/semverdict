<?php

declare(strict_types=1);

namespace Jbrada\Semverdict\Tests\Unit;

use Jbrada\Semverdict\Repository\RepositoryClient;
use Jbrada\Semverdict\Repository\RepositoryException;
use PHPUnit\Framework\TestCase;

/**
 * A repository whose v1 index is too large to read (a public repository's index
 * is hundreds of megabytes) must fail once, cleanly, and never be retried.
 */
class HttpMaxBytesTest extends TestCase
{
    public function testOversizedV1IndexIsAttemptedOnlyOnce(): void
    {
        $indexRequests = 0;
        $client = new RepositoryClient(
            'https://repo.example.org',
            httpGet: function (string $url, ?string $auth = null, ?int $maxBytes = null) use (&$indexRequests): string {
                if (str_contains($url, '/p2/')) {
                    throw new \RuntimeException('HTTP request failed (HTTP/1.1 404 Not Found)');
                }
                ++$indexRequests;
                self::assertNotNull($maxBytes, 'the index fetch must be size-capped');

                throw new \RuntimeException("Response from {$url} exceeds the {$maxBytes} byte limit.");
            },
        );

        foreach (['acme/one', 'acme/two', 'acme/three'] as $package) {
            try {
                $client->getReleases($package);
                self::fail('expected a RepositoryException');
            } catch (RepositoryException) {
                // expected: neither protocol can serve this package
            }
        }

        self::assertSame(1, $indexRequests, 'the unusable index must not be re-fetched per package');
    }

    public function testV1IndexIsFetchedOnceAndReusedAcrossPackages(): void
    {
        $indexRequests = 0;
        $client = new RepositoryClient(
            'https://composer.vendor.example',
            httpGet: function (string $url) use (&$indexRequests): string {
                if (str_contains($url, '/p2/')) {
                    throw new \RuntimeException('HTTP request failed (HTTP/1.1 403 Forbidden)');
                }
                ++$indexRequests;

                return json_encode([
                    'packages' => [
                        'acme/one' => ['1.0.0' => ['name' => 'acme/one', 'version' => '1.0.0']],
                        'acme/two' => ['2.0.0' => ['name' => 'acme/two', 'version' => '2.0.0']],
                    ],
                ], JSON_THROW_ON_ERROR);
            },
        );

        self::assertSame('1.0.0', $client->getReleases('acme/one')[0]->version);
        self::assertSame('2.0.0', $client->getReleases('acme/two')[0]->version);
        self::assertSame(1, $indexRequests, 'the index is the same file for every package');
    }
}
