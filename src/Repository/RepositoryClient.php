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
            throw new RepositoryException("Cannot fetch metadata for {$packageName}: {$e->getMessage()}", previous: $e);
        }

        $data = json_decode($body, true);
        $packages = is_array($data) && is_array($data['packages'] ?? null) ? $data['packages'] : [];
        $versions = $packages[$packageName] ?? null;
        if (!is_array($versions) || $versions === []) {
            throw new RepositoryException("No versions found for {$packageName} at {$url}.");
        }

        $expanded = MetadataMinifier::expand(array_values($versions));

        $releases = [];
        foreach ($expanded as $version) {
            if (!is_array($version)) {
                continue;
            }
            $name = $version['version'] ?? null;
            $normalized = $version['version_normalized'] ?? null;
            if (!is_string($name) || !is_string($normalized) || isset($releases[$normalized])) {
                continue;
            }
            $dist = is_array($version['dist'] ?? null) ? $version['dist'] : [];
            $releases[$normalized] = new Release(
                version: $name,
                normalized: $normalized,
                distUrl: is_string($dist['url'] ?? null) ? $dist['url'] : null,
                distType: is_string($dist['type'] ?? null) ? $dist['type'] : null,
                time: is_string($version['time'] ?? null) ? $version['time'] : null,
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
