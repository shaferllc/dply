<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Carbon;

/**
 * Evaluates org/server deploy window rules before queueing deploys.
 */
final class ServerDeployPolicyGuard
{
    /**
     * @return array{allowed: bool, reason: ?string, policy: array<string, mixed>, next_allowed_at: ?Carbon}
     */
    public function evaluateServer(Server $server, ?Carbon $at = null): array
    {
        $site = new Site(['server_id' => $server->id]);
        $site->setRelation('server', $server);

        return $this->evaluate($site, $at);
    }

    /**
     * @return array{allowed: bool, reason: ?string, policy: array<string, mixed>, next_allowed_at: ?Carbon}
     */
    public function evaluate(Site $site, ?Carbon $at = null): array
    {
        $at ??= now();
        $server = $site->server;
        if ($server === null) {
            return ['allowed' => true, 'reason' => null, 'policy' => $this->defaultPolicy(), 'next_allowed_at' => null];
        }

        $policy = $this->policyForServer($server);
        if (! ($policy['enabled'] ?? false)) {
            return ['allowed' => true, 'reason' => null, 'policy' => $policy, 'next_allowed_at' => null];
        }

        $timezone = (string) ($policy['timezone'] ?? config('app.timezone'));
        $local = $at->copy()->timezone($timezone);
        $denyRules = is_array($policy['deny_rules'] ?? null) ? $policy['deny_rules'] : [];

        foreach ($denyRules as $rule) {
            if (! is_array($rule)) {
                continue;
            }
            if ($this->matchesDenyRule($local, $rule)) {
                return [
                    'allowed' => false,
                    'reason' => (string) ($policy['message'] ?? __('Deploys are blocked by this server\'s deploy window policy.')),
                    'policy' => $policy,
                    'next_allowed_at' => null,
                ];
            }
        }

        return ['allowed' => true, 'reason' => null, 'policy' => $policy, 'next_allowed_at' => null];
    }

    /**
     * @return array<string, mixed>
     */
    public function policyForServer(Server $server): array
    {
        $meta = is_array($server->meta) ? $server->meta : [];
        $key = (string) config('server_deploy_policy.meta_key', 'deploy_policy');
        $stored = $meta[$key] ?? [];

        return $this->normalizePolicy(is_array($stored) ? $stored : []);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
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
     * @return array<string, mixed>
     */
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
    public function weekendFreezePreset(): array
    {
        $preset = config('server_deploy_policy.weekend_freeze_preset', []);

        return is_array($preset) ? $this->normalizeDenyRules($preset) : [];
    }

    /**
     * @return list<array{days: list<string>, start: string, end: string}>
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
