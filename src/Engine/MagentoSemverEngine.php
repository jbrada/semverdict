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
        if (!is_array($payload) || !isset($payload['level'])) {
            return AnalysisResult::failure('analyze-pair produced invalid JSON output.');
        }

        return AnalysisResult::success(
            BumpLevel::fromLevelInt((int) $payload['level']),
            $payload['changes'] ?? [],
        );
    }

    public function name(): string
    {
        return $this->policy === 'strict' ? 'magento-semver+strict-php' : 'magento-semver';
    }
}
