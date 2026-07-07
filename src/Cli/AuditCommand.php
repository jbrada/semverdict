<?php

declare(strict_types=1);

namespace Jbrada\Semverdict\Cli;

use Jbrada\Semverdict\Archive\ArchiveCache;
use Jbrada\Semverdict\Audit\AuditOptions;
use Jbrada\Semverdict\Audit\Auditor;
use Jbrada\Semverdict\Engine\MagentoSemverEngine;
use Jbrada\Semverdict\Report\ConsoleReporter;
use Jbrada\Semverdict\Report\JsonReporter;
use Jbrada\Semverdict\Repository\Release;
use Jbrada\Semverdict\Repository\RepositoryClient;
use Jbrada\Semverdict\Repository\RepositoryException;
use Jbrada\Semverdict\Support\PackageName;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'audit',
    description: 'Audit whether a composer package\'s release history followed semantic versioning',
)]
class AuditCommand extends Command
{
    public const EXIT_COMPLIANT = 0;
    public const EXIT_VIOLATIONS = 1;
    public const EXIT_FATAL = 2;

    /** Report types understood by magento-semver's ReportBuilder. */
    private const REPORT_TYPES = ['api', 'all', 'dbSchema', 'diXml', 'layout', 'systemXml', 'xsd', 'less', 'et_schema', 'mftf'];

    protected function configure(): void
    {
        $this
            ->addArgument('package', InputArgument::REQUIRED, 'Composer package name (vendor/package)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output machine-readable JSON on stdout')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Count 0.x under-bumps as violations')
            ->addOption('include-prereleases', null, InputOption::VALUE_NONE, 'Include alpha/beta/RC releases')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Audit only the N most recent version pairs')
            ->addOption('repo', null, InputOption::VALUE_REQUIRED, 'Composer repository base URL', 'https://repo.packagist.org')
            ->addOption('auth', null, InputOption::VALUE_REQUIRED, 'Basic auth for --repo as user:pass')
            ->addOption('cache-dir', null, InputOption::VALUE_REQUIRED, 'Directory for downloaded releases', getcwd() . '/.semverdict-cache')
            ->addOption('report-types', null, InputOption::VALUE_REQUIRED, 'Comma-separated magento-semver report types (default: all)')
            ->addOption('policy', null, InputOption::VALUE_REQUIRED, 'Versioning policy: "magento" (@api contract, non-API PHP dampened to patch) or "strict" (every public PHP signature is a contract)', 'magento');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $packageInput = $input->getArgument('package');
        $package = strtolower(is_string($packageInput) ? $packageInput : '');
        if (!PackageName::isValid($package)) {
            $output->writeln("<error>Invalid package name: {$package}</error>");

            return self::EXIT_FATAL;
        }

        $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $auth = self::stringOption($input, 'auth');
        $limit = self::stringOption($input, 'limit');
        $reportTypesInput = self::stringOption($input, 'report-types');
        $reportTypes = $reportTypesInput !== null
            ? array_values(array_filter(array_map('trim', explode(',', $reportTypesInput))))
            : [];
        if ($unknown = array_diff($reportTypes, self::REPORT_TYPES)) {
            $output->writeln(sprintf(
                '<error>Unknown report type(s): %s (expected any of %s)</error>',
                implode(', ', $unknown),
                implode(', ', self::REPORT_TYPES),
            ));

            return self::EXIT_FATAL;
        }
        $policy = self::stringOption($input, 'policy') ?? 'magento';
        if (!in_array($policy, ['magento', 'strict'], true)) {
            $output->writeln("<error>Invalid --policy: {$policy} (expected magento or strict)</error>");

            return self::EXIT_FATAL;
        }

        $projectRoot = dirname(__DIR__, 2);
        $auditor = new Auditor(
            new RepositoryClient(self::stringOption($input, 'repo') ?? 'https://repo.packagist.org', $auth),
            new ArchiveCache(self::stringOption($input, 'cache-dir') ?? getcwd() . '/.semverdict-cache', $auth),
            new MagentoSemverEngine(
                workerPath: $projectRoot . '/bin/analyze-pair',
                includesPath: $projectRoot . '/resources/module_includes.txt',
                excludesPath: $projectRoot . '/resources/module_excludes.txt',
                policy: $policy,
            ),
        );

        $options = new AuditOptions(
            strict: (bool) $input->getOption('strict'),
            includePrereleases: (bool) $input->getOption('include-prereleases'),
            limit: $limit !== null ? max(1, (int) $limit) : null,
            reportTypes: $reportTypes,
        );

        $progress = function (int $current, int $total, Release $from, Release $to) use ($stderr): void {
            $stderr->writeln("[{$current}/{$total}] {$from->version} → {$to->version}", OutputInterface::VERBOSITY_NORMAL);
        };

        try {
            $report = $auditor->audit($package, $options, $progress);
        } catch (RepositoryException $e) {
            $stderr->writeln("<error>{$e->getMessage()}</error>");

            return self::EXIT_FATAL;
        }

        if ($input->getOption('json')) {
            (new JsonReporter())->report($report, $output);
        } else {
            (new ConsoleReporter())->report($report, $output);
        }

        if ($report->hasFailures()) {
            $stderr->writeln('<comment>Warning: some pairs failed to analyze; the verdict is partial.</comment>');
        }

        return $report->followsSemver() ? self::EXIT_COMPLIANT : self::EXIT_VIOLATIONS;
    }

    private static function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) ? $value : null;
    }
}
