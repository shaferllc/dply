<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Exceptions\LogShippingException;
use App\Jobs\InstallLogAgentJob;
use App\Jobs\UninstallLogAgentJob;
use App\Models\Server;
use App\Models\ServerLogAgent;
use App\Models\ServerLogUsageDaily;
use App\Modules\Logs\Services\ServerLogEntitlements;

/**
 * The single code path for the dply Logs add-on lifecycle — enable, re-sync,
 * disable, and inspect the per-server edge Vector agent.
 *
 * Every surface drives the add-on through this action so the guard rules live
 * once: the Livewire workspace ({@see \App\Livewire\Servers\Concerns\ManagesServerLogShipping}),
 * the REST API ({@see \App\Http\Controllers\Api\ServerLogShippingController}),
 * and MCP ({@see \App\Mcp\Tools\Logs}). On a refusal it throws
 * {@see LogShippingException}; callers translate that to a toast / 422 / MCP error.
 *
 * The mutating methods only persist state + dispatch the install/uninstall job —
 * the SSH work happens in the queued job. See docs/SERVER_LOGS_ADDON.md.
 */
class ManageServerLogShipping
{
    public function __construct(private ServerLogEntitlements $entitlements) {}

    /**
     * Enable shipping on a server: create the agent row if needed and dispatch
     * the install. Idempotent-ish — re-enabling an existing-but-idle agent simply
     * re-installs. Pass $sources to override which collectors are on (defaults to
     * the config defaults for a brand-new agent, or the agent's stored set).
     *
     * @param  array<string, bool>|null  $sources
     */
    public function enable(Server $server, ?array $sources = null): ServerLogAgent
    {
        $this->assertAddonAvailable($server);
        $this->assertVmHost($server);

        $agent = ServerLogAgent::query()->firstOrCreate(
            ['server_id' => $server->id],
            [
                'status' => ServerLogAgent::STATUS_PENDING,
                'enabled_sources' => $sources ?? ServerLogAgent::configuredSourceDefaults(),
            ],
        );

        if ($agent->isBusy()) {
            throw new LogShippingException('The log agent is already installing — wait for it to finish.');
        }

        $agent->update([
            'status' => ServerLogAgent::STATUS_INSTALLING,
            'enabled_sources' => $sources ?? $agent->resolvedSources(),
            'install_output' => '',
            'error_message' => null,
        ]);

        InstallLogAgentJob::dispatch($agent->id);

        return $agent->refresh();
    }

    /**
     * Re-render config + restart the agent on the box (idempotent install) so a
     * source toggle or a new aggregator endpoint takes effect.
     */
    public function resync(Server $server): ServerLogAgent
    {
        $this->assertAddonAvailable($server);

        $agent = $server->logAgent;
        if ($agent === null) {
            throw new LogShippingException('No log agent to re-sync — enable shipping first.');
        }

        if ($agent->isBusy()) {
            throw new LogShippingException('The log agent is busy — wait for the current operation to finish.');
        }

        $agent->update([
            'status' => ServerLogAgent::STATUS_INSTALLING,
            'install_output' => '',
            'error_message' => null,
        ]);

        InstallLogAgentJob::dispatch($agent->id);

        return $agent->refresh();
    }

    /**
     * Tear the agent off the box. Idempotent: a server with no agent is a no-op
     * and returns null. The row is deleted by {@see UninstallLogAgentJob} on
     * success.
     */
    public function disable(Server $server): ?ServerLogAgent
    {
        $agent = $server->logAgent;
        if ($agent === null) {
            return null;
        }

        if ($agent->status === ServerLogAgent::STATUS_UNINSTALLING) {
            return $agent;
        }

        $agent->update([
            'status' => ServerLogAgent::STATUS_UNINSTALLING,
            'error_message' => null,
        ]);

        UninstallLogAgentJob::dispatch($agent->id);

        return $agent->refresh();
    }

    /**
     * Read-only snapshot of where a server stands, including where its logs are
     * actually being shipped (the configured aggregator endpoint, or `blackhole`
     * when none is set — the agent installs healthy but discards everything).
     *
     * @return array<string, mixed>
     */
    public function status(Server $server): array
    {
        $agent = $server->logAgent;

        // Prefer the codified aggregator's recorded endpoint; fall back to the
        // manual config env. Mirrors VectorLogAgentInstallScripts::resolveAggregatorTarget().
        $aggregator = \App\Models\ServerLogAggregator::query()
            ->where('status', \App\Models\ServerLogAggregator::STATUS_RUNNING)
            ->whereNotNull('endpoint')
            ->orderByDesc('updated_at')
            ->first();
        $endpoint = $aggregator !== null && $aggregator->hasEdgeMaterial()
            ? trim((string) $aggregator->endpoint)
            : trim((string) config('server_logs.aggregator_endpoint', ''));

        $entitlement = $this->entitlements->forOrganization($server->organization);
        $monthBytes = $this->monthToDateBytes($server);

        return [
            'server_id' => $server->id,
            'addon_enabled' => (bool) config('server_logs.enabled', false),
            'installed' => $agent !== null,
            'status' => $agent?->status,
            'version' => $agent?->version,
            'last_seen_at' => $agent?->last_seen_at?->toIso8601String(),
            'error_message' => $agent?->error_message,
            'sources' => $agent !== null
                ? $agent->resolvedSources()
                : ServerLogAgent::configuredSourceDefaults(),
            'destination' => $endpoint !== '' ? $endpoint : 'blackhole (no aggregator endpoint configured)',
            'shipping' => $endpoint !== '',
            'entitlement' => $entitlement->toArray(),
            // Org-wide ingest this calendar month (the org is the billing unit;
            // metered from server_log_usage_daily — PR A). null included = unlimited.
            'usage' => [
                'month_bytes' => $monthBytes,
                'included_bytes' => $entitlement->includedBytes(),
                'over_included' => $entitlement->isOverIncluded($monthBytes),
                'retention_days' => $entitlement->retentionDays,
            ],
        ];
    }

    /**
     * Sum the org's metered ingest bytes for the current calendar month (UTC),
     * across every server it ships from. Source of truth = {@see ServerLogUsageDaily}.
     */
    private function monthToDateBytes(Server $server): int
    {
        return (int) ServerLogUsageDaily::query()
            ->where('organization_id', $server->organization_id)
            ->whereDate('day', '>=', now()->startOfMonth()->toDateString())
            ->sum('bytes');
    }

    private function assertAddonAvailable(Server $server): void
    {
        // Environment kill-switch first (ships the add-on dark before the
        // aggregator/ClickHouse tier exists in an env), then the per-org plan gate.
        if (! (bool) config('server_logs.enabled', false)) {
            throw new LogShippingException('The dply Logs add-on is not enabled in this environment.');
        }

        if (! $this->entitlements->forOrganization($server->organization)->available) {
            throw new LogShippingException('The dply Logs add-on is not available on your current plan.');
        }
    }

    private function assertVmHost(Server $server): void
    {
        if (! $server->isVmHost()) {
            throw new LogShippingException('Log shipping is only available on VM servers.');
        }
    }
}
