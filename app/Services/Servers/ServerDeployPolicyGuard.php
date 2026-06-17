<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use Illuminate\Support\Carbon;

/**
 * Evaluates org/server deploy window rules before queueing deploys.
 */
final class ServerDeployPolicyGuard
{
    /**
     * @return array{allowed: bool, reason: ?string, rule_summary: ?string, policy: array<string, mixed>, next_allowed_at: ?Carbon}
     */
    /** @return array<string, mixed> */
    public function evaluateServer(Server $server, ?Carbon $at = null): array
    {
        $site = new Site(['server_id' => $server->id]);
        $site->setRelation('server', $server);

        return $this->evaluate($site, $at);
    }

    /**
     * @return array{allowed: bool, reason: ?string, rule_summary: ?string, policy: array<string, mixed>, next_allowed_at: ?Carbon}
     */
    /** @return array<string, mixed> */
    public function evaluate(Site $site, ?Carbon $at = null): array
    {
        $at ??= now();
        $server = $site->server;
        if ($server === null) {
            return ['allowed' => true, 'reason' => null, 'rule_summary' => null, 'policy' => $this->defaultPolicy(), 'next_allowed_at' => null];
        }

        $policy = $this->policyForServer($server);
        if (! ($policy['enabled'] ?? false)) {
            return ['allowed' => true, 'reason' => null, 'rule_summary' => null, 'policy' => $policy, 'next_allowed_at' => null];
        }

        $timezone = (string) ($policy['timezone'] ?? config('app.timezone'));
        $local = $at->copy()->timezone($timezone);
        $denyRules = is_array($policy['deny_rules'] ?? null) ? $policy['deny_rules'] : [];

        foreach ($denyRules as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            if ($this->ruleMatchesLocal($local, $rule)) {
                return [
                    'allowed' => false,
                    'reason' => (string) ($policy['message'] ?? __('Deploys are blocked by this server\'s deploy window policy.')),
                    // The specific deny window that matched, captured so a skipped
                    // deployment can record WHICH rule blocked it (the message is a
                    // blanket policy string and doesn't identify the window).
                    'rule_summary' => $this->formatRuleSummary($rule),
                    'policy' => $policy,
                    'next_allowed_at' => $this->nextAllowedAt($server, $at),
                ];
            }
        }

        return ['allowed' => true, 'reason' => null, 'rule_summary' => null, 'policy' => $policy, 'next_allowed_at' => null];
    }

    /**
     * Rich deploy-policy workspace report for the server deploy windows page.
     *
     * @return array{
     *     overall: string,
     *     evaluation: array{allowed: bool, reason: ?string, next_allowed_at: ?Carbon},
     *     summary: array{
     *         enabled: bool,
     *         timezone: string,
     *         rule_count: int,
     *         active_rules_now: int,
     *         total_sites: int,
     *         skipped_deploys_7d: int,
     *     },
     *     policy: array<string, mixed>,
     *     rule_rows: list<array{
     *         index: int,
     *         days_label: string,
     *         start: string,
     *         end: string,
     *         overnight: bool,
     *         active_now: bool,
     *         summary: string,
     *     }>,
     *     site_rows: list<array{
     *         id: string,
     *         name: string,
     *         primary_hostname: string,
     *         status: string,
     *         status_label: string,
     *         detail: ?string,
     *         show_url: string,
     *     }>,
     *     recent_skips: list<array{
     *         id: string,
     *         site_name: string,
     *         finished_at: ?Carbon,
     *         message: string,
     *         site_url: string,
     *     }>,
     * }
     */
    /** @return array<string, mixed> */
    public function report(Server $server, ?Carbon $at = null): array
    {
        $at ??= now();
        $server->loadMissing('sites');
        $evaluation = $this->evaluateServer($server, $at);
        $policy = $evaluation['policy'];
        $enabled = (bool) ($policy['enabled'] ?? false);
        $timezone = (string) ($policy['timezone'] ?? config('app.timezone'));
        $local = $at->copy()->timezone($timezone);
        $denyRules = is_array($policy['deny_rules'] ?? null) ? $policy['deny_rules'] : [];

        $ruleRows = [];
        $activeRulesNow = 0;
        foreach ($denyRules as $index => $rule) {
            if (! is_array($rule)) {
                continue;
            }

            $activeNow = $enabled && $this->ruleMatchesLocal($local, $rule);
            if ($activeNow) {
                $activeRulesNow++;
            }

            $start = (string) ($rule['start'] ?? '');
            $end = (string) ($rule['end'] ?? '');
            $ruleRows[] = [
                'index' => $index,
                'days_label' => $this->formatDaysLabel(is_array($rule['days'] ?? null) ? $rule['days'] : []),
                'start' => $start,
                'end' => $end,
                'overnight' => $start !== '' && $end !== '' && $start > $end,
                'active_now' => $activeNow,
                'summary' => $this->formatRuleSummary($rule),
            ];
        }

        $allowed = (bool) $evaluation['allowed'];
        $overall = ! $enabled ? 'disabled' : ($allowed ? 'allowed' : 'blocked');

        $siteStatus = $overall === 'disabled' ? 'disabled' : ($allowed ? 'allowed' : 'blocked');
        $siteStatusLabel = match ($siteStatus) {
            'disabled' => __('Policy off'),
            'allowed' => __('Allowed now'),
            default => __('Blocked now'),
        };
        $siteDetail = $allowed ? null : (string) ($evaluation['reason'] ?? '');

        $siteRows = [];
        foreach ($server->sites->sortBy('name') as $site) {
            $siteRows[] = [
                'id' => (string) $site->id,
                'name' => $site->name,
                'primary_hostname' => $site->primaryDomain()?->hostname ?: $site->name,
                'status' => $siteStatus,
                'status_label' => $siteStatusLabel,
                'detail' => $siteDetail !== '' ? $siteDetail : null,
                'show_url' => route('sites.show', ['server' => $server, 'site' => $site]),
            ];
        }

        return [
            'overall' => $overall,
            'evaluation' => [
                'allowed' => $allowed,
                'reason' => $evaluation['reason'],
                'next_allowed_at' => $evaluation['next_allowed_at'],
            ],
            'summary' => [
                'enabled' => $enabled,
                'timezone' => $timezone,
                'rule_count' => count($ruleRows),
                'active_rules_now' => $activeRulesNow,
                'total_sites' => $server->sites->count(),
                'skipped_deploys_7d' => $this->recentPolicySkipCount($server, $policy),
            ],
            'policy' => $policy,
            'rule_rows' => $ruleRows,
            'site_rows' => $siteRows,
            'recent_skips' => $this->recentPolicySkips($server, $policy),
        ];
    }

    public function nextAllowedAt(Server $server, ?Carbon $at = null): ?Carbon
    {
        $at ??= now();
        $policy = $this->policyForServer($server);
        if (! ($policy['enabled'] ?? false)) {
            return null;
        }

        $timezone = (string) ($policy['timezone'] ?? config('app.timezone'));
        $local = $at->copy()->timezone($timezone);
        $denyRules = is_array($policy['deny_rules'] ?? null) ? $policy['deny_rules'] : [];

        foreach ($denyRules as $rule) {
            if (! is_array($rule) || ! $this->ruleMatchesLocal($local, $rule)) {
                continue;
            }

            $start = (string) ($rule['start'] ?? '');
            $end = (string) ($rule['end'] ?? '');
            if ($start === '' || $end === '') {
                return null;
            }

            $current = $local->format('H:i');

            if ($start <= $end) {
                $release = $local->copy()->setTimeFromTimeString($end)->addMinute();

                return $release->isFuture() ? $release->utc() : null;
            }

            if ($current >= $start) {
                return $local->copy()->addDay()->setTimeFromTimeString($end)->addMinute()->utc();
            }

            return $local->copy()->setTimeFromTimeString($end)->addMinute()->utc();
        }

        return null;
    }

    /**
     * @param  array<string, mixed> $days
     */
    public function formatDaysLabel(array $days): string
    {
        $labels = [
            'mon' => __('Mon'),
            'tue' => __('Tue'),
            'wed' => __('Wed'),
            'thu' => __('Thu'),
            'fri' => __('Fri'),
            'sat' => __('Sat'),
            'sun' => __('Sun'),
        ];

        $parts = [];
        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as $key) {
            if (in_array($key, $days, true)) {
                $parts[] = $labels[$key];
            }
        }

        return $parts !== [] ? implode(', ', $parts) : __('No days');
    }

    /**
     * @param  array{days: list<string>, start: string, end: string}  $rule
     */
    public function formatRuleSummary(array $rule): string
    {
        $days = ($rule['days'] );
        $start = (string) ($rule['start'] ?? '');
        $end = (string) ($rule['end'] ?? '');

        return $this->formatDaysLabel($days).' · '.$start.'–'.$end;
    }

    /**
     * @return array<string, mixed>
     */
    /** @return array<string, mixed> */
    public function policyForServer(Server $server): array
    {
        $meta = is_array($server->meta) ? $server->meta : [];
        $key = (string) config('server_deploy_policy.meta_key', 'deploy_policy');
        $stored = $meta[$key] ?? [];

        return $this->normalizePolicy(is_array($stored) ? $stored : []);
    }

    /**
     * @param  array<string, mixed> $input
     * @return array<string, mixed>
     */
    /** @return array<string, mixed> */
    public function normalizePolicy(array $input): array
    {
        $defaults = $this->defaultPolicy();

        return [
            'enabled' => (bool) ($input['enabled'] ?? $defaults['enabled']),
            'timezone' => is_string($input['timezone'] ?? null) && trim($input['timezone']) !== ''
                ? trim($input['timezone'])
                : $defaults['timezone'],
            'message' => is_string($input['message'] ?? null) && trim($input['message']) !== ''
                ? trim($input['message'])
                : $defaults['message'],
            'deny_rules' => $this->normalizeDenyRules($input['deny_rules'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed> $input
     * @return array<string, mixed>
     */
    /** @return array<string, mixed> */
    public function defaultPolicy(): array
    {
        return [
            'enabled' => false,
            'timezone' => (string) config('app.timezone'),
            'message' => __('Deploys are blocked outside the allowed window for this server.'),
            'deny_rules' => [],
        ];
    }

    /**
     * @return list<array{days: list<string>, start: string, end: string}>
     */
    /** @return array<string, mixed> */
    /**
     * @return list<array<string, list<string>|string>>
     */
    public function weekendFreezePreset(): array
    {
        $preset = config('server_deploy_policy.weekend_freeze_preset', []);

        return is_array($preset) ? $this->normalizeDenyRules($preset) : [];
    }

    /**
     * @return list<array<string, list<string>|string>>
     */
    private function normalizeDenyRules(mixed $rules): array
    {
        if (! is_array($rules)) {
            return [];
        }

        $normalized = [];
        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            $days = collect($rule['days'] ?? [])
                ->filter(fn (mixed $day): bool => is_string($day) && trim($day) !== '')
                ->map(fn (string $day): string => strtolower(trim($day)))
                ->values()
                ->all();
            $start = is_string($rule['start'] ?? null) ? trim($rule['start']) : '';
            $end = is_string($rule['end'] ?? null) ? trim($rule['end']) : '';
            if ($days === [] || $start === '' || $end === '') {
                continue;
            }
            $normalized[] = ['days' => $days, 'start' => $start, 'end' => $end];
        }

        return $normalized;
    }

    /**
     * @param  array{days: list<string>, start: string, end: string}  $rule
     */
    public function ruleMatchesLocal(Carbon $local, array $rule): bool
    {
        return $this->matchesDenyRule($local, $rule);
    }

    /**
     * @param  array<string, mixed> $policy
     */
    private function recentPolicySkipCount(Server $server, array $policy): int
    {
        return count($this->recentPolicySkips($server, $policy));
    }

    /**
     * @param  array<string, mixed> $policy
     * @return list<array{id: string, site_name: string, finished_at: Carbon\Carbon|null, message: string, site_url: string}>
     */
    private function recentPolicySkips(Server $server, array $policy): array
    {
        $message = trim((string) ($policy['message'] ?? ''));

        $deployments = SiteDeployment::query()
            ->with('site:id,name,server_id')
            ->where('status', SiteDeployment::STATUS_SKIPPED)
            ->where('finished_at', '>=', now()->subDays(7))
            ->whereHas('site', fn ($query) => $query->where('server_id', $server->id))
            ->where(function ($query) use ($message): void {
                $query->where('log_output', 'like', '%deploy window%');
                if ($message !== '') {
                    $query->orWhere('log_output', $message);
                }
            })
            ->orderByDesc('finished_at')
            ->limit(8)
            ->get();

        $rows = [];
        foreach ($deployments as $deployment) {
            $site = $deployment->site;
            if ($site === null) {
                continue;
            }

            $rows[] = [
                'id' => (string) $deployment->id,
                'site_name' => $site->name,
                'finished_at' => $deployment->finished_at,
                'message' => (string) ($deployment->log_output ?? ''),
                'site_url' => route('sites.show', ['server' => $server, 'site' => $site]),
            ];
        }

        return $rows;
    }

    /**
     * @param  array{days: list<string>, start: string, end: string}  $rule
     */
    private function matchesDenyRule(Carbon $local, array $rule): bool
    {
        $dayKey = strtolower(substr($local->englishDayOfWeek, 0, 3));
        if (! in_array($dayKey, $rule['days'], true)) {
            return false;
        }

        $current = $local->format('H:i');
        $start = $rule['start'];
        $end = $rule['end'];

        if ($start <= $end) {
            return $current >= $start && $current <= $end;
        }

        return $current >= $start || $current <= $end;
    }
}
