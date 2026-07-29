<?php

declare(strict_types=1);

namespace Jbrada\Semverdict\Cli;

use Jbrada\Semverdict\Engine\MagentoSemverEngine;

/**
 * Shared --report-types/--policy handling for the commands that run the
 * analysis engine.
 */
final class EngineOptions
{
    /** Report types understood by magento-semver's ReportBuilder. */
    public const REPORT_TYPES = ['api', 'all', 'dbSchema', 'diXml', 'layout', 'systemXml', 'xsd', 'less', 'et_schema', 'mftf'];

    public const POLICIES = ['magento', 'strict'];

    public const DEFAULT_POLICY = 'magento';

    public const POLICY_DESCRIPTION = 'Versioning policy: "magento" (default — @api contract, non-API PHP dampened to patch) or "strict" (every public PHP signature is a contract)';

    /**
     * @return list<string>
     *
     * @throws \InvalidArgumentException on unknown types
     */
    public static function parseReportTypes(?string $csv): array
    {
        $types = $csv !== null
            ? array_values(array_filter(array_map('trim', explode(',', $csv))))
            : [];
        if ($unknown = array_diff($types, self::REPORT_TYPES)) {
            throw new \InvalidArgumentException(sprintf('Unknown report type(s): %s (expected any of %s)', implode(', ', $unknown), implode(', ', self::REPORT_TYPES)));
        }

        return $types;
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function validatePolicy(string $policy): string
    {
        if (!in_array($policy, self::POLICIES, true)) {
            throw new \InvalidArgumentException("Invalid --policy: {$policy} (expected " . implode(' or ', self::POLICIES) . ')');
        }

        return $policy;
    }

    public static function engine(string $policy): MagentoSemverEngine
    {
        $projectRoot = dirname(__DIR__, 2);

        return new MagentoSemverEngine(
            workerPath: $projectRoot . '/bin/analyze-pair',
            includesPath: $projectRoot . '/resources/module_includes.txt',
            excludesPath: $projectRoot . '/resources/module_excludes.txt',
            policy: $policy,
        );
    }
}
