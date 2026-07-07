<?php

declare(strict_types=1);

namespace Jbrada\Semverdict\Audit;

use Composer\Semver\VersionParser;
use Jbrada\Semverdict\Archive\ArchiveCache;
use Jbrada\Semverdict\Archive\ArchiveException;
use Jbrada\Semverdict\Engine\EngineInterface;
use Jbrada\Semverdict\Repository\Release;
use Jbrada\Semverdict\Repository\RepositoryClient;
use Jbrada\Semverdict\Repository\RepositoryException;

class Auditor
{
    public function __construct(
        private readonly RepositoryClient $repository,
        private readonly ArchiveCache $cache,
        private readonly EngineInterface $engine,
        private readonly BumpClassifier $classifier = new BumpClassifier(),
    ) {
    }

    /**
     * @param callable(int, int, Release, Release): void|null $progress called before each pair comparison
     *
     * @throws RepositoryException when the package cannot be fetched or has fewer than 2 auditable releases
     */
    public function audit(string $package, AuditOptions $options, ?callable $progress = null): AuditReport
    {
        $releases = $this->repository->getReleases($package);

        $skipped = [];
        $auditable = [];
        foreach ($releases as $release) {
            $stability = VersionParser::parseStability($release->version);
            if ($stability === 'dev' || (!$options->includePrereleases && $stability !== 'stable')) {
                $skipped[] = "{$release->version} ({$stability})";
                continue;
            }
            if (!$release->hasZipDist()) {
                $skipped[] = "{$release->version} (no zip dist)";
                continue;
            }
            $auditable[] = $release;
        }

        if (count($auditable) < 2) {
            throw new RepositoryException(
                "Package {$package} has fewer than 2 auditable releases (nothing to compare).",
            );
        }

        $pairs = [];
        for ($i = 1, $n = count($auditable); $i < $n; $i++) {
            $pairs[] = [$auditable[$i - 1], $auditable[$i]];
        }
        if ($options->limit !== null && $options->limit > 0) {
            $pairs = array_slice($pairs, -$options->limit);
        }

        $results = [];
        foreach ($pairs as $index => [$from, $to]) {
            if ($progress !== null) {
                $progress($index + 1, count($pairs), $from, $to);
            }
            $results[] = $this->comparePair($package, $from, $to, $options);
        }

        return new AuditReport($package, $this->engine->name(), $options, $results, $skipped);
    }

    private function comparePair(string $package, Release $from, Release $to, AuditOptions $options): PairResult
    {
        $actual = $this->classifier->classifyActual($from->normalized, $to->normalized);

        try {
            $beforeDir = $this->cache->getSourceDir($package, $from);
            $afterDir = $this->cache->getSourceDir($package, $to);
        } catch (ArchiveException $e) {
            return new PairResult($from->version, $to->version, $actual, null, Verdict::Failed, error: $e->getMessage());
        }

        $analysis = $this->engine->compare($beforeDir, $afterDir, $options->reportTypes);
        if ($analysis->failed) {
            return new PairResult($from->version, $to->version, $actual, null, Verdict::Failed, error: $analysis->error);
        }

        $required = $analysis->requiredLevel;
        $verdict = match (true) {
            $actual === $required => Verdict::Ok,
            $actual->isAtLeast($required) => Verdict::Over,
            $this->classifier->isZeroDotX($from->normalized) && !$options->strict => Verdict::ZeroX,
            default => Verdict::Violation,
        };

        return new PairResult($from->version, $to->version, $actual, $required, $verdict, $analysis->changes);
    }
}
