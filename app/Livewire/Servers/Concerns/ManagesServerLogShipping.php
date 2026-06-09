<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Jobs\InstallLogAgentJob;
use App\Jobs\UninstallLogAgentJob;
use App\Models\ServerLogAgent;

/**
 * Drives the "Shipping" section of the Logs workspace — the dply Logs add-on:
 * enable/disable the per-server Vector agent, toggle which sources it collects,
 * re-sync after a change, and stream install progress. The live SSH log tail
 * ({@see ManagesServerSystemLogs}) is separate; this is the persistent,
 * ClickHouse-backed shipping pipeline. See docs/SERVER_LOGS_ADDON.md.
 *
 * Requires the host component to also use {@see \App\Livewire\Concerns\DispatchesToastNotifications}
 * and {@see InteractsWithServerWorkspace} (provides $server + authorize()).
 */
trait ManagesServerLogShipping
{
    /**
     * Per-source on/off state bound to the toggle checkboxes.
     *
     * @var array<string, bool>
     */
    public array $logShippingSources = [];

    /**
     * Hydrate the source toggles from the agent's resolved state (or config
     * defaults when no agent exists yet). Call from the component's mount().
     */
    protected function bootLogShipping(): void
    {
        $agent = $this->server->logAgent;

        $this->logShippingSources = $agent !== null
            ? $agent->resolvedSources()
            : ServerLogAgent::configuredSourceDefaults();
    }

    /**
     * True when the add-on is available in this environment (master kill-switch).
     */
    public function getLogShippingEnabledProperty(): bool
    {
        return (bool) config('server_logs.enabled', false);
    }

    /**
     * Source catalog for the toggle UI: key => human label.
     *
     * @return array<string, string>
     */
    public function getLogShippingSourceCatalogProperty(): array
    {
        $catalog = [];
        foreach ((array) config('server_logs.sources', []) as $key => $meta) {
            $catalog[(string) $key] = (string) ($meta['label'] ?? $key);
        }

        return $catalog;
    }

    public function toggleLogShippingSource(string $key): void
    {
        $this->authorize('update', $this->server);

        if (! array_key_exists($key, $this->logShippingSourceCatalog)) {
            return;
        }

        $this->logShippingSources[$key] = ! ($this->logShippingSources[$key] ?? false);

        // Persist to the agent if one exists; the change applies on the next
        // re-sync (we don't auto-restart the box on every checkbox click).
        $agent = $this->server->logAgent;
        if ($agent !== null) {
            $agent->update(['enabled_sources' => $this->logShippingSources]);
            $this->toastSuccess(__('Source updated — re-sync the agent to apply it on the server.'));
        }
    }

    public function enableLogShipping(): void
    {
        $this->authorize('update', $this->server);

        if (! $this->logShippingEnabled) {
            $this->toastError(__('The Logs add-on is not enabled in this environment yet.'));

            return;
        }

        if (! $this->server->isVmHost()) {
            $this->toastError(__('Log shipping is only available on VM servers.'));

            return;
        }

        $agent = ServerLogAgent::query()->firstOrCreate(
            ['server_id' => $this->server->id],
            [
                'status' => ServerLogAgent::STATUS_PENDING,
                'enabled_sources' => $this->logShippingSources,
            ],
        );

        if ($agent->isBusy()) {
            $this->toastError(__('The log agent is already installing — hang tight.'));

            return;
        }

        $agent->update([
            'status' => ServerLogAgent::STATUS_INSTALLING,
            'enabled_sources' => $this->logShippingSources,
            'install_output' => '',
            'error_message' => null,
        ]);

        InstallLogAgentJob::dispatch($agent->id);
        $this->server->load('logAgent');
        $this->toastSuccess(__('Installing the log agent — progress will stream below.'));
    }

    /**
     * Re-render config + restart the agent on the box (idempotent install).
     */
    public function resyncLogShipping(): void
    {
        $this->authorize('update', $this->server);

        $agent = $this->server->logAgent;
        if ($agent === null) {
            $this->toastError(__('No log agent to re-sync — enable it first.'));

            return;
        }

        if ($agent->isBusy()) {
            $this->toastError(__('The log agent is busy — wait for the current operation to finish.'));

            return;
        }

        $agent->update([
            'status' => ServerLogAgent::STATUS_INSTALLING,
            'install_output' => '',
            'error_message' => null,
        ]);

        InstallLogAgentJob::dispatch($agent->id);
        $this->server->load('logAgent');
        $this->toastSuccess(__('Re-syncing the log agent with the latest sources.'));
    }

    public function disableLogShipping(): void
    {
        $this->authorize('update', $this->server);

        $agent = $this->server->logAgent;
        if ($agent === null) {
            return;
        }

        $agent->update(['status' => ServerLogAgent::STATUS_UNINSTALLING, 'error_message' => null]);
        UninstallLogAgentJob::dispatch($agent->id);
        $this->server->load('logAgent');
        $this->toastSuccess(__('Removing the log agent from this server.'));
    }

    /**
     * wire:poll hook: refreshes the agent row so streaming install_output and
     * status transitions show up while a job runs.
     */
    public function pollLogShipping(): void
    {
        $this->server->load('logAgent');
    }
}
