<?php

declare(strict_types=1);

namespace Jbrada\Semverdict\Repository;

use Composer\MetadataMinifier\MetadataMinifier;
use Composer\Semver\Comparator;
use Jbrada\Semverdict\Support\Http;
use Jbrada\Semverdict\Support\PackageName;

class RepositoryClient
{
    /** @var callable(string, ?string): string */
    private $httpGet;

    public function __construct(
        private readonly string $repoBaseUrl = 'https://repo.packagist.org',
        private readonly ?string $basicAuth = null,
        ?callable $httpGet = null,
    ) {
        $this->httpGet = $httpGet ?? Http::get(...);
    }

    /**
     * Returns all released versions of a package, ascending by version,
     * deduplicated by normalized version.
     *
     * @return Release[]
     */
    public function getReleases(string $packageName): array
    {
        if (!PackageName::isValid($packageName)) {
            throw new RepositoryException("Invalid package name: {$packageName}");
        }

        $url = rtrim($this->repoBaseUrl, '/') . "/p2/{$packageName}.json";
        try {
            $body = ($this->httpGet)($url, $this->basicAuth);
        } catch (\RuntimeException $e) {
            throw new RepositoryException(
                "Cannot fetch metadata for {$packageName}: {$e->getMessage()}",
                previous: $e,
            );
        }

        $data = json_decode($body, true);
        $versions = $data['packages'][$packageName] ?? null;
        if (!is_array($versions) || $versions === []) {
            throw new RepositoryException("No versions found for {$packageName} at {$url}.");
        }

        $expanded = MetadataMinifier::expand($versions);

        $releases = [];
        foreach ($expanded as $version) {
            $normalized = $version['version_normalized'] ?? null;
            if ($normalized === null || isset($releases[$normalized])) {
                continue;
            }
            $releases[$normalized] = new Release(
                version: $version['version'],
                normalized: $normalized,
                distUrl: $version['dist']['url'] ?? null,
                distType: $version['dist']['type'] ?? null,
                time: $version['time'] ?? null,
            );
        }

        $releases = array_values($releases);
        usort(
            $releases,
            static fn (Release $a, Release $b): int => Comparator::lessThan($a->normalized, $b->normalized) ? -1 : 1,
        );

        return $releases;
    }
}
