<?php

declare(strict_types=1);

namespace Jbrada\Semverdict\Cli;

use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use Jbrada\Semverdict\Audit\BumpLevel;
use Jbrada\Semverdict\Git\GitException;
use Jbrada\Semverdict\Git\GitWorkingCopy;
use Jbrada\Semverdict\Support\Dir;
use Jbrada\Semverdict\Tag\TagSuggester;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'next',
    description: 'Suggest the next release tag for a git working copy by comparing it against its latest tag',
)]
class NextCommand extends Command
{
    public const EXIT_OK = 0;
    public const EXIT_FATAL = 2;

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'Package root: the repo root or any directory inside it', '.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output machine-readable JSON on stdout')
            ->addOption('tag', null, InputOption::VALUE_REQUIRED, 'Baseline tag to compare against (default: highest stable semver tag)')
            ->addOption('include-prereleases', null, InputOption::VALUE_NONE, 'Allow a pre-release tag as the baseline')
            ->addOption('report-types', null, InputOption::VALUE_REQUIRED, 'Comma-separated magento-semver report types (default: all)')
            ->addOption('policy', null, InputOption::VALUE_REQUIRED, EngineOptions::POLICY_DESCRIPTION, EngineOptions::DEFAULT_POLICY);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        try {
            $reportTypes = EngineOptions::parseReportTypes(self::stringOption($input, 'report-types'));
            $policy = EngineOptions::validatePolicy(self::stringOption($input, 'policy') ?? EngineOptions::DEFAULT_POLICY);
        } catch (\InvalidArgumentException $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");

            return self::EXIT_FATAL;
        }

        $pathInput = $input->getArgument('path');
        $path = is_string($pathInput) ? $pathInput : '.';
        try {
            $git = GitWorkingCopy::open($path);
            $baseline = $this->resolveBaselineTag($git, $input, $output);
            if ($baseline === null) {
                return self::EXIT_FATAL;
            }

            $stderr->writeln("Comparing the working tree against {$baseline}…", OutputInterface::VERBOSITY_NORMAL);
            $tempDir = sys_get_temp_dir() . '/semverdict-next-' . bin2hex(random_bytes(6));
            try {
                $beforeDir = $git->exportTag($baseline, $tempDir . '/before');
                $afterDir = $tempDir . '/after';
                $git->exportWorkingTree($afterDir);
                $analysis = EngineOptions::engine($policy)->compare($beforeDir, $afterDir, $reportTypes);
            } finally {
                Dir::remove($tempDir);
            }
        } catch (GitException $e) {
            $output->writeln("<error>{$e->getMessage()}</error>");

            return self::EXIT_FATAL;
        }

        $required = $analysis->requiredLevel;
        if ($analysis->failed || $required === null) {
            $output->writeln('<error>Analysis failed: ' . ($analysis->error ?? 'unknown error') . '</error>');

            return self::EXIT_FATAL;
        }

        $suggestion = (new TagSuggester())->suggest($baseline, $required);

        if ($input->getOption('json')) {
            $output->writeln(json_encode(
                [
                    'path' => $git->packageDir(),
                    'engine' => EngineOptions::engine($policy)->name(),
                    'baselineTag' => $baseline,
                    'requiredLevel' => strtolower($required->name),
                    'suggestedTag' => $suggestion->tag,
                    'notes' => $suggestion->notes,
                    'changes' => $analysis->changes,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));

            return self::EXIT_OK;
        }

        $this->renderConsole($output, $baseline, $required, $suggestion->tag, $suggestion->notes, $analysis->changes);

        return self::EXIT_OK;
    }

    /**
     * Picks the baseline tag: --tag when given (must exist and be semver-shaped),
     * otherwise the highest stable semver tag. Returns null after printing the
     * error when no baseline can be determined.
     */
    private function resolveBaselineTag(GitWorkingCopy $git, InputInterface $input, OutputInterface $output): ?string
    {
        $tagOption = self::stringOption($input, 'tag');
        if ($tagOption !== null) {
            if (!$git->tagExists($tagOption)) {
                $output->writeln("<error>Tag not found in this repository: {$tagOption}</error>");

                return null;
            }
            if (!TagSuggester::isSemverTag($tagOption)) {
                $output->writeln("<error>Cannot compute a next version from non-semver tag {$tagOption}.</error>");

                return null;
            }

            return $tagOption;
        }

        $includePrereleases = (bool) $input->getOption('include-prereleases');
        $parser = new VersionParser();
        $best = null;
        $bestNormalized = null;
        foreach ($git->tags() as $tag) {
            if (!TagSuggester::isSemverTag($tag)) {
                continue;
            }
            try {
                $normalized = $parser->normalize($tag);
            } catch (\UnexpectedValueException) {
                continue;
            }
            $stability = VersionParser::parseStability($normalized);
            if ($stability === 'dev' || (!$includePrereleases && $stability !== 'stable')) {
                continue;
            }
            if ($bestNormalized === null || Comparator::greaterThan($normalized, $bestNormalized)) {
                $best = $tag;
                $bestNormalized = $normalized;
            }
        }

        if ($best === null) {
            $output->writeln('<error>No semver tags found to compare against.</error>');
            $output->writeln('<comment>Tag the first release yourself: v1.0.0 for a stable API, v0.1.0 while it is still settling.</comment>');
        }

        return $best;
    }

    /**
     * @param list<string> $notes
     * @param array<int, array{context: string, level: string, location: string, target: string, reason: string, code: string}> $changes
     */
    private function renderConsole(
        OutputInterface $output,
        string $baseline,
        BumpLevel $required,
        ?string $suggestedTag,
        array $notes,
        array $changes,
    ): void {
        $output->writeln("Baseline: <options=bold>{$baseline}</>");
        $output->writeln('Required bump: ' . $required->label());

        // Without -v, show only the changes that force the bump — the "why".
        $shown = $output->isVerbose()
            ? $changes
            : array_filter($changes, fn (array $c) => self::levelRank($c['level']) >= $required->value);
        if ($shown !== []) {
            $output->writeln('');
            foreach ($shown as $change) {
                $output->writeln(sprintf(
                    '  %-5s %-9s %s %s — %s (%s)',
                    strtoupper($change['level']),
                    $change['context'],
                    $change['location'],
                    $change['target'],
                    $change['reason'],
                    $change['code'],
                ));
            }
        } elseif ($required === BumpLevel::Patch) {
            $output->writeln('  Files changed without affecting any analyzed contract — still at least a patch release.');
        }

        if ($notes !== []) {
            $output->writeln('');
            foreach ($notes as $note) {
                $output->writeln("<comment>{$note}</comment>");
            }
        }

        $output->writeln('');
        $output->writeln($suggestedTag !== null
            ? "<info>Suggested next tag: {$suggestedTag}</info>"
            : '<info>✔ No release needed.</info>');
    }

    private static function levelRank(string $levelName): int
    {
        return match ($levelName) {
            'patch' => 1,
            'minor' => 2,
            'major' => 3,
            default => 0,
        };
    }

    private static function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) ? $value : null;
    }
}
