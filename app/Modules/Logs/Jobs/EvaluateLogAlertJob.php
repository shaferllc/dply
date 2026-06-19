<?php

declare(strict_types=1);

namespace App\Modules\Logs\Jobs;

use App\Models\Server;
use App\Models\ServerLogAlertRule;
use App\Modules\Logs\Services\LogExplorerQuery;
use App\Modules\Logs\Services\ServerLogEntitlements;
use App\Modules\Notifications\Services\NotificationPublisher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Evaluate one dply Logs alert rule against the log store and, on a breach,
 * publish a notification through the org's configured channels. A ClickHouse
 * COUNT (read-only, like the explorer) — never SSH — so it's safe on a worker.
 *
 * Re-checks every gate the command applied (defensive against a rule disabled or
 * a plan downgraded after dispatch), measures the matched count over the rolling
 * window, and notifies only when count >= threshold AND the rule is out of its
 * post-fire cooldown. last_evaluated_at / last_count are always refreshed so the
 * UI shows the latest reading even while suppressed by cooldown.
 */
class EvaluateLogAlertJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 30;

    /** Auto-expire the unique lock so a lost run can't wedge it. */
    public int $uniqueFor = 120;

    public function __construct(public string $ruleId) {}

    public function uniqueId(): string
    {
        return 'log-alert:'.$this->ruleId;
    }

    public function handle(
        LogExplorerQuery $explorer,
        ServerLogEntitlements $entitlements,
        NotificationPublisher $publisher,
    ): void {
        if (! (bool) config('server_logs.enabled', false)) {
            return;
        }

        $rule = ServerLogAlertRule::query()->with('server.organization')->find($this->ruleId);
        if ($rule === null || ! $rule->enabled) {
            return;
        }

        $server = $rule->server;
        if (! $server instanceof Server || $server->organization === null) {
            return;
        }

        if (! $entitlements->forOrganization($server->organization)->alertingEnabled) {
            return;
        }

        $window = max(1, $rule->window_minutes);
        $to = now();
        $from = $to->copy()->subMinutes($window);

        $count = $explorer->countInWindow($server, $from, $to, $rule->facetFilters());

        $rule->forceFill([
            'last_evaluated_at' => $to,
            'last_count' => $count,
        ])->save();

        if ($count < max(1, $rule->threshold) || $rule->isInCooldown()) {
            return;
        }

        $publisher->publish(
            eventKey: 'server.logs.alert_triggered',
            subject: $server,
            title: '['.config('app.name').'] '.$server->name.' — '.$rule->name,
            body: $this->bodyLine($rule, $count, $window),
            url: route('servers.logs', ['server' => $server, 'tab' => 'alerts']),
            metadata: [
                'server_id' => $server->id,
                'log_alert_rule_id' => $rule->id,
                'rule_name' => $rule->name,
                'type' => $rule->type,
                'count' => $count,
                'threshold' => $rule->threshold,
                'window_minutes' => $window,
                'level' => $rule->level,
                'source' => $rule->source,
                'search' => $rule->search,
            ],
        );

        $rule->forceFill(['last_fired_at' => now()])->save();
    }

    private function bodyLine(ServerLogAlertRule $rule, int $count, int $window): string
    {
        $facets = [];
        if (($rule->level ?? '') !== '') {
            $facets[] = __('level :level', ['level' => $rule->level]);
        }
        if (($rule->source ?? '') !== '') {
            $facets[] = __('source :source', ['source' => $rule->source]);
        }
        if (($rule->search ?? '') !== '') {
            $facets[] = __('matching ":search"', ['search' => $rule->search]);
        }

        $scope = $facets === [] ? __('log lines') : __('log lines (:facets)', ['facets' => implode(', ', $facets)]);

        return __(':count :scope in the last :mins min — threshold is :threshold.', [
            'count' => $count,
            'scope' => $scope,
            'mins' => $window,
            'threshold' => $rule->threshold,
        ]);
    }
}
