<?php

declare(strict_types=1);

namespace Jbrada\Semverdict\Engine;

use Jbrada\Semverdict\Audit\BumpLevel;
use Symfony\Component\Process\Process;

/**
 * Runs magento-semver in a fresh child process per comparison (bin/analyze-pair):
 * its ReportBuilder mutates global analyzer state, and a fatal on one weird old
 * release must not kill the whole audit.
 */
class MagentoSemverEngine implements EngineInterface
{
    public function __construct(
        private readonly string $workerPath,
        private readonly string $includesPath,
        private readonly string $excludesPath,
        private readonly string $policy = 'magento',
        private readonly string $phpBinary = PHP_BINARY,
        private readonly int $timeoutSeconds = 300,
    ) {
    }

    public function compare(string $beforeDir, string $afterDir, array $reportTypes = []): AnalysisResult
    {
        $process = new Process(
            [
                $this->phpBinary,
                $this->workerPath,
                $beforeDir,
                $afterDir,
                implode(',', $reportTypes),
                $this->includesPath,
                $this->excludesPath,
                $this->policy,
            ],
            timeout: $this->timeoutSeconds,
        );

        try {
            $process->run();
        } catch (\Throwable $e) {
            return AnalysisResult::failure($e->getMessage());
        }

        if (!$process->isSuccessful()) {
            $error = trim($process->getErrorOutput()) ?: trim($process->getOutput()) ?: 'unknown error';

            return AnalysisResult::failure(
                "analyze-pair exited with code {$process->getExitCode()}: " . substr($error, 0, 500),
            );
        }

        $payload = json_decode($process->getOutput(), true);
        $level = is_array($payload) ? ($payload['level'] ?? null) : null;
        if (!is_int($level) || BumpLevel::tryFrom($level) === null) {
            return AnalysisResult::failure('analyze-pair produced invalid JSON output.');
        }

        return AnalysisResult::success(
            BumpLevel::from($level),
            self::normalizeChanges(is_array($payload['changes'] ?? null) ? $payload['changes'] : []),
        );
    }

    /**
     * The worker's JSON is trusted in practice but typed as mixed at this boundary;
     * keep only well-formed change entries.
     *
     * @param array<mixed> $rawChanges
     *
     * @return array<int, array{context: string, level: string, location: string, target: string, reason: string, code: string}>
     */
    private static function normalizeChanges(array $rawChanges): array
    {
        $changes = [];
        foreach ($rawChanges as $change) {
            if (!is_array($change)) {
                continue;
            }
            $context = $change['context'] ?? null;
            $level = $change['level'] ?? null;
            $location = $change['location'] ?? null;
            $target = $change['target'] ?? null;
            $reason = $change['reason'] ?? null;
            $code = $change['code'] ?? null;
            if (!is_string($context) || !is_string($level) || !is_string($location)
                || !is_string($target) || !is_string($reason) || !is_string($code)
            ) {
                continue;
            }
            $changes[] = [
                'context' => $context,
                'level' => $level,
                'location' => $location,
                'target' => $target,
                'reason' => $reason,
                'code' => $code,
            ];
        }

        return $changes;
    }

    public function name(): string
    {
        return $this->policy === 'strict' ? 'magento-semver+strict-php' : 'magento-semver';
    }
}
