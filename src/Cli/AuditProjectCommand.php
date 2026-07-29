<?php

declare(strict_types=1);

namespace Jbrada\Semverdict\Cli;

use Jbrada\Semverdict\Archive\ArchiveCache;
use Jbrada\Semverdict\Audit\AuditOptions;
use Jbrada\Semverdict\Audit\Auditor;
use Jbrada\Semverdict\Audit\AuditReport;
use Jbrada\Semverdict\Project\ComposerProject;
use Jbrada\Semverdict\Project\ProjectException;
use Jbrada\Semverdict\Repository\Release;
use Jbrada\Semverdict\Repository\RepositoryClient;
use Jbrada\Semverdict\Repository\RepositoryException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'audit-project',
    description: 'Audit every first-party dependency of a Composer project, resolving private repositories and auth automatically',
)]
class AuditProjectCommand extends Command
{
    public const EXIT_COMPLIANT = 0;
    public const EXIT_VIOLATIONS = 1;
    public const EXIT_FATAL = 2;

    protected function configure(): void
    {
        $this
            ->addArgument('project', InputArgument::OPTIONAL, 'Path to the Composer project', '.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output machine-readable JSON on stdout')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Count 0.x under-bumps as violations')
            ->addOption('include-prereleases', null, InputOption::VALUE_NONE, 'Include alpha/beta/RC releases')
            ->addOption('include-magento', null, InputOption::VALUE_NONE, 'Also audit magento/* requires (skipped by default)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Audit only the N most recent version pairs per package', '10')
            ->addOption('cache-dir', null, InputOption::VALUE_REQUIRED, 'Directory for downloaded releases', getcwd() . '/.semverdict-cache')
            ->addOption('report-types', null, InputOption::VALUE_REQUIRED, 'Comma-separated magento-semver report types (default: all)')
            ->addOption('policy', null, InputOption::VALUE_REQUIRED, EngineOptions::POLICY_DESCRIPTION, 'magento');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $projectArg = $input->getArgument('project');
        try {
            $project = new ComposerProject(is_string($projectArg) ? $projectArg : '.');
            $reportTypes = EngineOptions::parseReportTypes(self::stringOption($input, 'report-types'));
            $policy = EngineOptions::validatePolicy(self::stringOption($input, 'policy') ?? 'magento');
        } catch (ProjectException|\InvalidArgumentException $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");

            return self::EXIT_FATAL;
        }

        $packages = $project->directRequires((bool) $input->getOption('include-magento'));
        if ($packages === []) {
            $output->writeln('<comment>No auditable first-party requires found.</comment>');

            return self::EXIT_COMPLIANT;
        }

        $repos = $project->repositories();
        $limit = self::stringOption($input, 'limit');
        $options = new AuditOptions(
            strict: (bool) $input->getOption('strict'),
            includePrereleases: (bool) $input->getOption('include-prereleases'),
            limit: $limit !== null ? max(1, (int) $limit) : null,
            reportTypes: $reportTypes,
        );

        $cacheDir = self::stringOption($input, 'cache-dir') ?? getcwd() . '/.semverdict-cache';
        $clients = [];
        $auditors = [];
        $clientFor = function (string $repo) use (&$clients, $project): RepositoryClient {
            return $clients[$repo] ??= new RepositoryClient($repo, $project->authFor($repo));
        };
        // The Auditor shares the repo's client, whose metadata lookups are
        // memoized — resolving the serving repository costs no extra request.
        $auditorFor = function (string $repo) use (&$auditors, $clientFor, $project, $cacheDir, $policy): Auditor {
            return $auditors[$repo] ??= new Auditor(
                $clientFor($repo),
                new ArchiveCache($cacheDir, $project->authFor($repo)),
                EngineOptions::engine($policy),
            );
        };

        $progress = function (int $current, int $total, Release $from, Release $to) use ($stderr): void {
            $stderr->writeln("  [{$current}/{$total}] {$from->version} → {$to->version}", OutputInterface::VERBOSITY_VERBOSE);
        };

        /** @var array<string, string> $vendorRepoHint vendor prefix -> repo that served it */
        $vendorRepoHint = [];
        $rows = [];
        $total = count($packages);
        foreach ($packages as $index => $package) {
            $stderr->writeln(sprintf('<info>[%d/%d] %s</info>', $index + 1, $total, $package));

            $vendor = explode('/', $package, 2)[0];
            $candidates = $repos;
            if (isset($vendorRepoHint[$vendor])) {
                $candidates = array_values(array_unique(array_merge([$vendorRepoHint[$vendor]], $repos)));
            }

            // Resolve which repository actually serves the package first, so a
            // package-specific problem (e.g. too few releases to compare) is
            // reported as such instead of being masked by the next candidate's
            // "not found".
            $servedBy = null;
            $firstError = null;
            foreach ($candidates as $repo) {
                try {
                    $clientFor($repo)->getReleases($package);
                    $servedBy = $repo;
                    $vendorRepoHint[$vendor] = $repo;
                    break;
                } catch (RepositoryException $e) {
                    $firstError ??= $e->getMessage();
                }
            }

            $report = null;
            $error = null;
            if ($servedBy === null) {
                $error = str_ends_with($package, '-implementation')
                    ? 'virtual package — provided by an implementation, has no releases of its own'
                    : 'not found in any configured repository (' . ($firstError ?? 'no repositories configured') . ')';
            } else {
                try {
                    $report = $auditorFor($servedBy)->audit($package, $options, $progress);
                } catch (RepositoryException $e) {
                    $error = $e->getMessage();
                }
            }

            $rows[] = [
                'package' => $package,
                'repo' => $servedBy,
                'report' => $report,
                'error' => $error,
            ];
        }

        if ($input->getOption('json')) {
            $this->reportJson($project->dir, $rows, $output);
        } else {
            $this->reportTable($rows, $output);
        }

        $anyViolations = false;
        $anyAudited = false;
        foreach ($rows as $row) {
            if ($row['report'] instanceof AuditReport) {
                $anyAudited = true;
                $anyViolations = $anyViolations || !$row['report']->followsSemver();
            }
        }
        if (!$anyAudited) {
            return self::EXIT_FATAL;
        }

        return $anyViolations ? self::EXIT_VIOLATIONS : self::EXIT_COMPLIANT;
    }

    /**
     * @param list<array{package: string, repo: ?string, report: ?AuditReport, error: ?string}> $rows
     */
    private function reportTable(array $rows, OutputInterface $output): void
    {
        $table = new Table($output);
        $table->setHeaders(['Package', 'Verdict', 'Pairs', 'Violations', 'Source']);
        foreach ($rows as $row) {
            $report = $row['report'];
            if ($report === null) {
                $source = $row['repo'] !== null
                    ? (parse_url($row['repo'], PHP_URL_HOST) ?: $row['repo'])
                    : '';
                $table->addRow([
                    $row['package'],
                    '<comment>— not audited</comment>',
                    '',
                    '',
                    trim($source . ' ' . $this->shortError($row['error'])),
                ]);
                continue;
            }
            $summary = $report->summary();
            $table->addRow([
                $row['package'],
                $report->followsSemver() ? '<info>✔ follows semver</info>' : '<error>✘ violations</error>',
                $summary['pairs'],
                $summary['violations'],
                $row['repo'] !== null ? (parse_url($row['repo'], PHP_URL_HOST) ?: $row['repo']) : '',
            ]);
        }
        $table->render();
    }

    /**
     * @param list<array{package: string, repo: ?string, report: ?AuditReport, error: ?string}> $rows
     */
    private function reportJson(string $projectDir, array $rows, OutputInterface $output): void
    {
        $packages = [];
        $compliant = $violations = $unresolved = 0;
        foreach ($rows as $row) {
            $report = $row['report'];
            if ($report === null) {
                ++$unresolved;
                $packages[] = ['package' => $row['package'], 'error' => $row['error']];
                continue;
            }
            $report->followsSemver() ? $compliant++ : $violations++;
            $packages[] = [
                'package' => $row['package'],
                'repo' => $row['repo'],
                'followsSemver' => $report->followsSemver(),
                'summary' => $report->summary(),
            ];
        }

        $output->writeln((string) json_encode([
            'project' => $projectDir,
            'packages' => $packages,
            'summary' => [
                'total' => count($rows),
                'compliant' => $compliant,
                'violations' => $violations,
                'unresolved' => $unresolved,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function shortError(?string $error): string
    {
        if ($error === null) {
            return '';
        }
        $firstLine = strtok($error, "\n");

        return strlen((string) $firstLine) > 70 ? substr((string) $firstLine, 0, 67) . '…' : (string) $firstLine;
    }

    private static function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) ? $value : null;
    }
}
