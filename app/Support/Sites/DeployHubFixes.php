<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Site;
use App\Models\SiteDeployment;
use App\Modules\Remediations\Services\RemediationCatalog;

/**
 * One card per unique deploy-hub fix. Pipeline check, the remediations
 * catalog, and SiteFixers used to stack the same issue in three places
 * (Upgrade PHP + Install php-redis showing twice). This collapses them.
 */
final class DeployHubFixes
{
    /**
     * SiteFixer keys already owned by a remediations catalog code.
     *
     * @var array<string, list<string>>
     */
    private const FIXER_ALIASES = [
        'php_ext_redis_missing' => ['install_php_redis', 'composer_install'],
        'php_pdo_driver_missing' => ['install_pgsql_driver', 'install_mysql_driver'],
        'php_version_too_low' => ['upgrade_php', 'composer_install'],
    ];

    /**
     * Pipeline-check keys that must not also appear as a fixer card.
     *
     * @var list<string>
     */
    private const PIPELINE_STEP_FIXERS = [
        'migrate',
        'storage_link',
        'build_assets',
        'npm_ci_for_build',
    ];

    /**
     * @param  list<array<string, mixed>>  $pipelineSuggestions
     * @param  list<string>  $completedFixerKeys
     * @return list<array<string, mixed>>
     */
    public static function cards(
        Site $site,
        ?SiteDeployment $latest = null,
        array $pipelineSuggestions = [],
        array $completedFixerKeys = [],
    ): array {
        $latest ??= $site->latestDeployment();

        $cards = [];
        $claimed = array_fill_keys($completedFixerKeys, true);
        $runtimeBlocked = false;

        foreach ($pipelineSuggestions as $suggestion) {
            $key = (string) $suggestion['key'];
            $claimed[$key] = true;

            if (($suggestion['action'] ?? '') === '') {
                continue;
            }

            $cards[] = [
                'id' => $key,
                'source' => 'pipeline',
                'title' => (string) $suggestion['label'],
                'reason' => (string) $suggestion['reason'],
                'method' => 'addSuggestedPipelineStep',
                'args' => [$key],
                'button' => __('Apply fix'),
                'dismiss_key' => $key,
            ];
            $claimed['php_version_too_low'] = true;
            if (($suggestion['action'] ?? '') === 'upgrade_php') {
                $runtimeBlocked = true;
            }
        }

        if ($runtimeBlocked) {
            foreach (self::PIPELINE_STEP_FIXERS as $alias) {
                $claimed[$alias] = true;
            }
        }

        $failureText = $latest instanceof SiteDeployment && $latest->status === SiteDeployment::STATUS_FAILED
            ? self::failureText($latest)
            : '';

        if ($failureText === '') {
            return $cards;
        }

        foreach (app(RemediationCatalog::class)->matchAll($failureText) as $remediation) {
            if (! empty($remediation['guided'])) {
                continue;
            }

            $code = (string) ($remediation['code'] ?? '');
            if ($code === '' || isset($claimed[$code])) {
                continue;
            }

            $action = collect($remediation['actions'] ?? [])->firstWhere('recommended', true)
                ?? collect($remediation['actions'] ?? [])->first();
            if (! is_array($action) || ($action['key'] ?? '') === '') {
                continue;
            }

            $card = [
                'id' => $code,
                'source' => 'remediation',
                'title' => (string) ($remediation['title'] ?? $action['label']),
                'reason' => (string) ($remediation['explanation'] ?? ''),
                'button' => (string) ($action['label'] ?? __('Apply fix')),
            ];

            if (! empty($action['route']) && is_string($action['route'])) {
                $card['href'] = route($action['route']);
            } else {
                $card['method'] = 'applyDeploymentRemediation';
                $card['args'] = [(string) $latest->id, (string) $action['key']];
            }

            $cards[] = $card;
            $claimed[$code] = true;
            foreach (self::FIXER_ALIASES[$code] ?? [] as $alias) {
                $claimed[$alias] = true;
            }
        }

        foreach (SiteFixers::detect($failureText) as $fixer) {
            $key = (string) $fixer['key'];
            if (isset($claimed[$key])) {
                continue;
            }

            $cards[] = [
                'id' => $key,
                'source' => 'fixer',
                'title' => (string) $fixer['label'],
                'reason' => (string) $fixer['reason'],
                'method' => 'runFixer',
                'args' => [$key],
                'button' => (string) $fixer['label'],
            ];
            $claimed[$key] = true;
        }

        return $cards;
    }

    /**
     * Pipeline-check rows that are missing deploy steps — not server remediations.
     *
     * @param  list<array<string, mixed>>  $pipelineSuggestions
     * @return list<array<string, mixed>>
     */
    public static function pipelineStepSuggestions(array $pipelineSuggestions): array
    {
        return array_values(array_filter(
            $pipelineSuggestions,
            static fn (array $suggestion): bool => ($suggestion['action'] ?? '') === '',
        ));
    }

    private static function failureText(SiteDeployment $deployment): string
    {
        $parts = [(string) $deployment->log_output];
        $phaseResults = is_array($deployment->phase_results ?? null) ? $deployment->phase_results : [];
        array_walk_recursive($phaseResults, static function ($value) use (&$parts): void {
            if (is_string($value) && $value !== '') {
                $parts[] = $value;
            }
        });

        return implode("\n", $parts);
    }
}
