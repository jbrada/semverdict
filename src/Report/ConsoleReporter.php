<?php

declare(strict_types=1);

namespace Jbrada\Semverdict\Report;

use Jbrada\Semverdict\Audit\AuditReport;
use Jbrada\Semverdict\Audit\Verdict;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;

class ConsoleReporter
{
    public function report(AuditReport $report, OutputInterface $output): void
    {
        $table = new Table($output);
        $table->setHeaders(['From', 'To', 'Actual', 'Required', 'Verdict']);
        foreach ($report->pairs as $pair) {
            $table->addRow([
                $pair->fromVersion,
                $pair->toVersion,
                $pair->actual->label(),
                $pair->required?->label() ?? '—',
                $this->colorize($pair->verdict),
            ]);
        }
        $table->render();

        foreach ($report->pairs as $pair) {
            // Without -v, detail only the pairs that need explaining: for violations,
            // just the changes exceeding the bump the author actually made.
            $offendingOnly = !$output->isVerbose();
            if ($offendingOnly && $pair->verdict !== Verdict::Violation && $pair->verdict !== Verdict::ZeroX && $pair->error === null) {
                continue;
            }
            $changes = $offendingOnly
                ? array_filter($pair->changes, fn (array $c) => $this->levelRank($c['level']) > $pair->actual->value)
                : $pair->changes;
            if ($changes === [] && $pair->error === null) {
                continue;
            }
            $output->writeln('');
            $output->writeln("<options=bold>{$pair->fromVersion} → {$pair->toVersion}</> [{$pair->verdict->value}]");
            if ($pair->error !== null) {
                $output->writeln("  <fg=red>{$pair->error}</>");
            }
            foreach ($changes as $change) {
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
        }

        if ($report->skippedReleases !== []) {
            $output->writeln('');
            $output->writeln('<comment>Skipped releases: ' . implode(', ', $report->skippedReleases) . '</comment>');
        }

        $summary = $report->summary();
        $output->writeln('');
        $output->writeln(sprintf(
            'Pairs: %d | OK: %d | Over-bumped: %d | Violations: %d | 0.x exempt: %d | Failed: %d',
            $summary['pairs'],
            $summary['ok'],
            $summary['over'],
            $summary['violations'],
            $summary['zeroXExempt'],
            $summary['failed'],
        ));

        if ($report->followsSemver()) {
            $suffix = $report->hasFailures() ? ' (verdict is partial — some pairs failed to analyze)' : '';
            $output->writeln("<info>✔ {$report->package} follows semantic versioning{$suffix}</info>");
        } else {
            $output->writeln(sprintf(
                '<error>✘ %s violated semantic versioning in %d release(s)</error>',
                $report->package,
                $summary['violations'],
            ));
        }
    }

    private function levelRank(string $levelName): int
    {
        return match ($levelName) {
            'patch' => 1,
            'minor' => 2,
            'major' => 3,
            default => 0,
        };
    }

    private function colorize(Verdict $verdict): string
    {
        return match ($verdict) {
            Verdict::Ok => '<info>OK</info>',
            Verdict::Over => '<fg=cyan>OVER</>',
            Verdict::Violation => '<error>VIOLATION</error>',
            Verdict::ZeroX => '<comment>ZERO_X</comment>',
            Verdict::Failed => '<fg=magenta>FAILED</>',
        };
    }
}
