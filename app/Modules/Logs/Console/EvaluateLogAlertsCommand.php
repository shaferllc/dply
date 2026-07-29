<?php

declare(strict_types=1);

namespace App\Modules\Logs\Console;

use App\Models\ServerLogAlertRule;
use App\Modules\Logs\Jobs\EvaluateLogAlertJob;
use App\Modules\Logs\Services\ServerLogEntitlements;
use Illuminate\Console\Command;

/**
 * dply Logs alerting evaluator (paid tier — docs/SERVER_LOGS_BILLING.md). Fans
 * the enabled alert rules out to {@see EvaluateLogAlertJob}, one job per rule, so
 * a slow ClickHouse count on one rule never blocks the others. Gated three ways
 * so it stays inert until the add-on + a plan that includes alerting + a rule all
 * exist:
 *   - the master kill-switch (config server_logs.enabled),
 *   - the org's `alerting_enabled` entitlement, and
 *   - the server actually shipping logs (a running agent).
 *
 *   php artisan dply:logs:evaluate-alerts
 */
class EvaluateLogAlertsCommand extends Command
{
    protected $signature = 'dply:logs:evaluate-alerts';

    protected $description = 'Evaluate dply Logs alert rules and notify on threshold breaches.';

    public function handle(ServerLogEntitlements $entitlements): int
    {
        if (! (bool) config('server_logs.enabled', false)) {
            $this->info('dply Logs add-on disabled (server_logs.enabled) — nothing to evaluate.');

            return self::SUCCESS;
        }

        $rules = ServerLogAlertRule::query()
            ->where('enabled', true)
            ->with(['server.logAgent', 'server.organization'])
            ->get();

        // Resolve each org's alerting entitlement once per run.
        $alertingByOrg = [];
        $dispatched = 0;

        foreach ($rules as $rule) {
            $server = $rule->server;
            if ($server === null || $server->organization === null) {
                continue;
            }

            // No point counting logs for a server that isn't shipping any.
            if (! $server->logAgent?->isRunning()) {
                continue;
            }

            $orgId = (string) $server->organization_id;
            $alertingByOrg[$orgId] ??= $entitlements->forOrganization($server->organization)->alertingEnabled;
            if (! $alertingByOrg[$orgId]) {
                continue;
            }

            EvaluateLogAlertJob::dispatch($rule->id)->onQueue((string) config('server_logs.install_queue', 'dply'));
            $dispatched++;
        }

        $this->info("Dispatched {$dispatched} log-alert evaluation(s).");

        return self::SUCCESS;
    }
}
