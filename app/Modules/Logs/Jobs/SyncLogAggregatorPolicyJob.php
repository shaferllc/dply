<?php

declare(strict_types=1);

namespace App\Modules\Logs\Jobs;

use App\Models\Server;
use App\Models\ServerLogAggregator;
use App\Modules\Logs\Services\ServerLogAggregatorPolicyMap;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Support\Servers\VectorLogAggregatorInstallScripts;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * Ships the refreshed per-org policy CSV (retention_days + hard-cap allow) to a
 * running dply Logs aggregator and reloads Vector so it rereads the enrichment
 * table. Idempotent on the box — the bash only swaps + restarts when the file
 * actually changed. Dispatched per running aggregator by
 * {@see \App\Modules\Logs\Console\SyncLogAggregatorPolicyCommand} on a schedule.
 *
 * See docs/SERVER_LOGS_BILLING.md §3.2 (PR B2 / C2).
 */
class SyncLogAggregatorPolicyJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 2;

    public int $uniqueFor = 300;

    public function __construct(public string $serverLogAggregatorId)
    {
        $queue = config('server_logs.install_queue');
        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    public function uniqueId(): string
    {
        return 'sync-log-aggregator-policy:'.$this->serverLogAggregatorId;
    }

    public function handle(
        ExecuteRemoteTaskOnServer $executor,
        VectorLogAggregatorInstallScripts $scripts,
        ServerLogAggregatorPolicyMap $policy,
    ): void {
        /** @var ServerLogAggregator|null $aggregator */
        $aggregator = ServerLogAggregator::query()->with('server')->find($this->serverLogAggregatorId);
        if ($aggregator === null || ! $aggregator->isRunning()) {
            return;
        }

        $server = $aggregator->server;
        if (! $server instanceof Server || ! $server->isVmHost()) {
            return;
        }

        $csvB64 = base64_encode($policy->toCsv());

        $output = $executor->runInlineBash(
            $server,
            'log-aggregator:sync-policy',
            $scripts->syncPolicyScript($csvB64),
            timeoutSeconds: 120,
            asRoot: true,
        );

        if ($output->exitCode !== 0) {
            throw new \RuntimeException(
                Str::limit(trim($output->buffer), 500) ?: 'Aggregator policy sync failed.'
            );
        }
    }
}
