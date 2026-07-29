<?php

declare(strict_types=1);

namespace Jbrada\Semverdict\Repository;

use Composer\MetadataMinifier\MetadataMinifier;
use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use Jbrada\Semverdict\Support\Http;
use Jbrada\Semverdict\Support\PackageName;

class RepositoryClient
{
    /** @var callable(string, ?string): string */
    private $httpGet;

    /** @var array<string, Release[]> */
    private array $releaseCache = [];

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
     * Tries the Composer v2 metadata endpoint first, then falls back to a v1
     * `packages.json` — several Magento vendor repositories (Amasty, BSS
     * Commerce) still only speak v1.
     *
     * @return Release[]
     */
    public function getReleases(string $packageName): array
    {
        if (!PackageName::isValid($packageName)) {
            throw new RepositoryException("Invalid package name: {$packageName}");
        }
        if (isset($this->releaseCache[$packageName])) {
            return $this->releaseCache[$packageName];
        }

        try {
            $releases = $this->releasesFromV2($packageName);
        } catch (RepositoryException $v2Error) {
            try {
                $releases = $this->releasesFromV1($packageName);
            } catch (RepositoryException) {
                // The v2 error is the more informative one (it names the
                // package endpoint and carries the HTTP status).
                throw $v2Error;
            }
        }

        return $this->releaseCache[$packageName] = $releases;
    }

    /**
     * @return Release[]
     */
    private function releasesFromV2(string $packageName): array
    {
        $url = rtrim($this->repoBaseUrl, '/') . "/p2/{$packageName}.json";
        $data = $this->fetchJson($url, "metadata for {$packageName}");

        $packages = is_array($data['packages'] ?? null) ? $data['packages'] : [];
        $versions = $packages[$packageName] ?? null;
        if (!is_array($versions) || $versions === []) {
            throw new RepositoryException("No versions found for {$packageName} at {$url}.");
        }

        return $this->toReleases(MetadataMinifier::expand(array_values($versions)));
    }

    /**
     * Composer v1: the root `packages.json` either carries package definitions
     * inline, or points at further files via `includes` / `provider-includes`
     * (+ `providers-url`).
     *
     * @return Release[]
     */
    private function releasesFromV1(string $packageName): array
    {
        $base = rtrim($this->repoBaseUrl, '/');
        $root = $this->fetchJson($base . '/packages.json', 'repository index');

        $versions = $this->findPackageInV1($root, $packageName);

        if ($versions === null) {
            foreach (array_keys($this->stringKeyedArray($root['includes'] ?? null)) as $include) {
                $included = $this->fetchJson($base . '/' . ltrim($include, '/'), 'repository include');
                $versions = $this->findPackageInV1($included, $packageName);
                if ($versions !== null) {
                    break;
                }
            }
        }

        if ($versions === null && is_string($root['providers-url'] ?? null)) {
            $versions = $this->findPackageViaProviders($base, (string) $root['providers-url'], $root, $packageName);
        }

        if ($versions === null || $versions === []) {
            throw new RepositoryException("No versions found for {$packageName} in the v1 repository at {$base}.");
        }

        return $this->toReleases(array_values($versions));
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>|null
     */
    private function findPackageInV1(array $document, string $packageName): ?array
    {
        $packages = $this->stringKeyedArray($document['packages'] ?? null);
        $versions = $packages[$packageName] ?? null;
        if (!is_array($versions) || $versions === []) {
            return null;
        }

        return $this->stringKeyedArray($versions);
    }

    /**
     * @param array<string, mixed> $root
     *
     * @return array<string, mixed>|null
     */
    private function findPackageViaProviders(string $base, string $providersUrl, array $root, string $packageName): ?array
    {
        // The package's provider file is listed in one of the provider-includes.
        foreach (array_keys($this->stringKeyedArray($root['provider-includes'] ?? null)) as $providerInclude) {
            $listing = $this->fetchJson($base . '/' . ltrim($providerInclude, '/'), 'provider listing');
            $providers = $this->stringKeyedArray($listing['providers'] ?? null);
            if (!isset($providers[$packageName])) {
                continue;
            }
            $hash = is_array($providers[$packageName]) ? ($providers[$packageName]['sha256'] ?? null) : null;
            $path = str_replace(
                ['%package%', '%hash%'],
                [$packageName, is_string($hash) ? $hash : ''],
                $providersUrl,
            );
            $document = $this->fetchJson($base . '/' . ltrim($path, '/'), "provider metadata for {$packageName}");
            $versions = $this->findPackageInV1($document, $packageName);
            if ($versions !== null) {
                return $versions;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchJson(string $url, string $what): array
    {
        try {
            $body = ($this->httpGet)($url, $this->basicAuth);
        } catch (\RuntimeException $e) {
            throw new RepositoryException("Cannot fetch {$what}: {$e->getMessage()}", previous: $e);
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RepositoryException("Cannot parse {$what} from {$url}: response is not JSON.");
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param list<mixed> $versions
     *
     * @return Release[]
     */
    private function toReleases(array $versions): array
    {
        $parser = new VersionParser();
        $releases = [];
        foreach ($versions as $version) {
            if (!is_array($version)) {
                continue;
            }
            $name = $version['version'] ?? null;
            if (!is_string($name)) {
                continue;
            }
            $normalized = $version['version_normalized'] ?? null;
            if (!is_string($normalized)) {
                // v1 metadata carries no version_normalized — derive it, and
                // drop anything Composer cannot parse (branch names, aliases).
                try {
                    $normalized = $parser->normalize($name);
                } catch (\UnexpectedValueException) {
                    continue;
                }
            }
            if (isset($releases[$normalized])) {
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

    /**
     * @return array<string, mixed>
     */
    private function stringKeyedArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $item) {
            $result[(string) $key] = $item;
        }

        return $result;
    }
}
