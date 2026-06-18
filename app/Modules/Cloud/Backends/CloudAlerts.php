<?php

declare(strict_types=1);

namespace App\Modules\Cloud\Backends;

use App\Models\Organization;
use App\Models\Site;

/**
 * Cloud-site alert configuration + DO App Platform spec emission.
 *
 * dply ships four alert types matching DO's native rules:
 *  - DEPLOYMENT_FAILED   — no threshold, fires on every failed deploy
 *  - RESTART_COUNT       — N restarts in M minutes
 *  - CPU_UTILIZATION     — % CPU over M minutes
 *  - MEM_UTILIZATION     — % memory over M minutes
 *
 * All four default ON for new sites with sensible thresholds. Org-level
 * destinations (Slack + extra emails) apply to every site unless the
 * site opts in to its own override.
 */
class CloudAlerts
{
    public const RULE_DEPLOYMENT_FAILED = 'DEPLOYMENT_FAILED';

    public const RULE_RESTART_COUNT = 'RESTART_COUNT';

    public const RULE_CPU_UTILIZATION = 'CPU_UTILIZATION';

    public const RULE_MEM_UTILIZATION = 'MEM_UTILIZATION';

    /**
     * Sensible defaults applied to every new Cloud site. Operators tune
     * thresholds in the dashboard; defaults match common SaaS PaaS norms.
     *
     * @return array<string, mixed>
     */
    public static function defaultConfig(): array
    {
        return [
            'deployment_failed' => ['enabled' => true],
            'restart_count' => ['enabled' => true, 'value' => 3, 'window' => 'FIVE_MINUTES'],
            'cpu_utilization' => ['enabled' => true, 'value' => 80, 'window' => 'FIVE_MINUTES'],
            'mem_utilization' => ['enabled' => true, 'value' => 80, 'window' => 'FIVE_MINUTES'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function forSite(Site $site): array
    {
        $meta = ($site->meta );
        $stored = is_array($meta['container']['alerts'] ?? null) ? $meta['container']['alerts'] : [];

        return array_replace_recursive(self::defaultConfig(), $stored);
    }

    /**
     * Build the DO `alerts` array for an app spec from a site's
     * stored alert config. DEPLOYMENT_FAILED has no threshold/operator/
     * window; the three threshold-based rules carry value + window.
     *
     * @return list<array<string, mixed>>
     */
    public static function doAlertsBlock(Site $site): array
    {
        $cfg = self::forSite($site);
        $alerts = [];

        if (! empty($cfg['deployment_failed']['enabled'])) {
            $alerts[] = ['rule' => self::RULE_DEPLOYMENT_FAILED];
        }

        foreach ([
            'restart_count' => self::RULE_RESTART_COUNT,
            'cpu_utilization' => self::RULE_CPU_UTILIZATION,
            'mem_utilization' => self::RULE_MEM_UTILIZATION,
        ] as $key => $rule) {
            if (empty($cfg[$key]['enabled'])) {
                continue;
            }
            $alerts[] = [
                'rule' => $rule,
                'operator' => 'GREATER_THAN',
                'value' => (float) ($cfg[$key]['value'] ?? 80),
                'window' => (string) ($cfg[$key]['window'] ?? 'FIVE_MINUTES'),
            ];
        }

        return $alerts;
    }

    /**
     * Build the destinations payload for DO's PUT alert destinations
     * endpoint. Combines org-level defaults (Slack webhook + extra
     * emails + org-owner emails) with any per-site override stored on
     * the site's meta.
     *
     * @return array{slack_webhooks: list<array{url: string}>, emails: list<string>}
     */
    public static function destinationsFor(Site $site, Organization $organization): array
    {
        $meta = ($site->meta );
        $override = is_array($meta['container']['alerts']['destinations_override'] ?? null)
            ? $meta['container']['alerts']['destinations_override']
            : null;

        $slack = (string) ($override['slack_webhook_url'] ?? $organization->alert_slack_webhook_url ?? '');
        $emails = is_array($override['emails'] ?? null)
            ? $override['emails']
            : (is_array($organization->alert_extra_emails ?? null) ? $organization->alert_extra_emails : []);

        // Org owners are always notified — their login emails go into
        // the recipient list automatically so a fresh org with no
        // configured destinations still pages someone.
        $owners = $organization->users()
            ->wherePivot('role', 'owner')
            ->pluck('email')
            ->filter(fn ($e) => is_string($e) && $e !== '')
            ->all();

        $emails = array_values(array_unique(array_filter(array_merge($owners, $emails), 'is_string')));

        $slackWebhooks = [];
        if ($slack !== '') {
            $slackWebhooks[] = ['url' => $slack];
        }

        return [
            'slack_webhooks' => $slackWebhooks,
            'emails' => $emails,
        ];
    }
}
