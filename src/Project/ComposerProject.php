<?php

declare(strict_types=1);

namespace Jbrada\Semverdict\Project;

use Jbrada\Semverdict\Support\PackageName;

/**
 * Reads the parts of a Composer project that audit-project needs: the
 * first-party requires, the configured repositories (in Composer's
 * precedence order), and per-host credentials from auth.json/COMPOSER_AUTH.
 */
class ComposerProject
{
    public const PACKAGIST = 'https://repo.packagist.org';

    /** @var array<string, mixed> */
    private readonly array $composerJson;

    /** @var array<string, mixed> */
    private readonly array $authJson;

    public function __construct(public readonly string $dir)
    {
        $composerFile = rtrim($dir, '/') . '/composer.json';
        if (!is_file($composerFile)) {
            throw new ProjectException("No composer.json found in {$dir}");
        }
        $decoded = json_decode((string) file_get_contents($composerFile), true);
        if (!is_array($decoded)) {
            throw new ProjectException("Could not parse {$composerFile}");
        }
        /** @var array<string, mixed> $decoded */
        $this->composerJson = $decoded;

        $authFile = rtrim($dir, '/') . '/auth.json';
        $auth = is_file($authFile) ? json_decode((string) file_get_contents($authFile), true) : null;
        /** @var array<string, mixed> $auth */
        $auth = is_array($auth) ? $auth : [];
        $this->authJson = $auth;
    }

    /**
     * Direct requires that are real packages (vendor/name). Platform
     * requirements (php, ext-*) never match; magento/* is excluded by
     * default because the core metapackages are pinned bundles, not
     * semver-promising code.
     *
     * @return list<string>
     */
    public function directRequires(bool $includeMagento = false): array
    {
        $requires = $this->composerJson['require'] ?? [];
        if (!is_array($requires)) {
            return [];
        }

        $packages = [];
        foreach (array_keys($requires) as $name) {
            $name = strtolower((string) $name);
            if (!PackageName::isValid($name)) {
                continue;
            }
            if (!$includeMagento && str_starts_with($name, 'magento/')) {
                continue;
            }
            $packages[] = $name;
        }
        sort($packages);

        return $packages;
    }

    /**
     * Composer-type repository base URLs in declared order, with Packagist
     * appended last unless the project disabled it ("packagist.org": false).
     *
     * @return list<string>
     */
    public function repositories(): array
    {
        $entries = $this->composerJson['repositories'] ?? [];
        if (!is_array($entries)) {
            $entries = [];
        }

        $urls = [];
        $packagistDisabled = false;
        foreach ($entries as $key => $entry) {
            if ($entry === false && in_array((string) $key, ['packagist', 'packagist.org'], true)) {
                $packagistDisabled = true;
                continue;
            }
            if (is_array($entry) && array_key_exists('packagist.org', $entry) && $entry['packagist.org'] === false) {
                $packagistDisabled = true;
                continue;
            }
            if (is_array($entry) && ($entry['type'] ?? null) === 'composer' && is_string($entry['url'] ?? null)) {
                $urls[] = rtrim($entry['url'], '/');
            }
        }
        if (!$packagistDisabled) {
            $urls[] = self::PACKAGIST;
        }

        return array_values(array_unique($urls));
    }

    /**
     * Basic-auth credentials for a repository URL as "user:pass", resolved
     * from the project's auth.json first, then the COMPOSER_AUTH env var.
     */
    public function authFor(string $repoUrl): ?string
    {
        $host = parse_url($repoUrl, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return null;
        }

        foreach ([$this->authJson, self::envAuth()] as $source) {
            $httpBasic = $source['http-basic'] ?? null;
            $entry = is_array($httpBasic) ? ($httpBasic[$host] ?? null) : null;
            if (is_array($entry) && is_string($entry['username'] ?? null) && is_string($entry['password'] ?? null)) {
                return "{$entry['username']}:{$entry['password']}";
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function envAuth(): array
    {
        $raw = getenv('COMPOSER_AUTH');
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        /** @var array<string, mixed> $decoded */
        $decoded = is_array($decoded) ? $decoded : [];

        return $decoded;
    }
}
