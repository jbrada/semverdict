<?php

declare(strict_types=1);

namespace Jbrada\Semverdict\Report;

use Jbrada\Semverdict\Audit\AuditReport;
use Symfony\Component\Console\Output\OutputInterface;

class JsonReporter
{
    public function report(AuditReport $report, OutputInterface $output): void
    {
        $pairs = [];
        foreach ($report->pairs as $pair) {
            $pairs[] = [
                'from' => $pair->fromVersion,
                'to' => $pair->toVersion,
                'actual' => strtolower($pair->actual->name),
                'required' => $pair->required !== null ? strtolower($pair->required->name) : null,
                'verdict' => $pair->verdict->value,
                'changes' => $pair->changes,
                'error' => $pair->error,
            ];
        }

        $output->writeln(json_encode(
            [
                'package' => $report->package,
                'engine' => $report->engine,
                'options' => [
                    'strict' => $report->options->strict,
                    'includePrereleases' => $report->options->includePrereleases,
                    'limit' => $report->options->limit,
                    'reportTypes' => $report->options->reportTypes,
                ],
                'pairs' => $pairs,
                'skippedReleases' => $report->skippedReleases,
                'summary' => $report->summary(),
                'followsSemver' => $report->followsSemver(),
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ));
    }
}
