<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Livewire\Live\Concerns\ConfirmsProductionWrites;
use App\Services\ProductionData\ProductionApiClient;
use App\Services\ProductionData\ProductionDataMirror;
use Livewire\Component;

/**
 * The Metrics workspace's three operations, aimed at the control plane that
 * owns a mirrored host instead of at local SSH.
 *
 * Each one is a real production mutation, so it goes through the same
 * type-PRODUCTION confirmation the /live pages use — once per browser session.
 *
 * @phpstan-require-extends Component
 */
trait ManagesMonitorProductionMirror
{
    use ConfirmsProductionWrites;
    use InteractsWithProductionMirrorServer;

    protected function productionMirror(): ProductionDataMirror
    {
        return app(ProductionDataMirror::class);
    }

    protected function proxyMonitoringProbeToProduction(): void
    {
        $this->runProductionWrite(
            'runProductionMonitoringProbe',
            [],
            __('Recheck SSH on production'),
            __('This asks production to open an SSH connection to :server. Type PRODUCTION to continue for this browser session.', [
                'server' => $this->server->name,
            ]),
        );
    }

    public function runProductionMonitoringProbe(): void
    {
        $this->authorize('view', $this->server);

        $serverId = $this->server->id;
        $queued = $this->withProductionMirrorClient(
            fn (ProductionApiClient $client): array => $client->queueServerMonitoringProbe($serverId),
        );

        if ($queued) {
            $this->wasProbePending = $this->probePendingFromMeta($this->server->meta ?? []);
            $this->toastSuccess(__('Probe queued on production.'));
        }
    }

    protected function proxyMonitoringInstallToProduction(): void
    {
        $this->runProductionWrite(
            'runProductionMonitoringInstall',
            [],
            __('Install the metrics agent on production'),
            __('This installs Python and the metrics agent on :server over SSH, from production. Type PRODUCTION to continue for this browser session.', [
                'server' => $this->server->name,
            ]),
        );
    }

    public function runProductionMonitoringInstall(): void
    {
        $this->authorize('update', $this->server);

        $serverId = $this->server->id;
        $this->withProductionMirrorClient(
            fn (ProductionApiClient $client): array => $client->installServerMonitoring($serverId),
            __('Install queued on production. This page updates as the agent reports back.'),
        );
    }

    /**
     * @param  array{cpu: float, mem: float, load: float}  $thresholds
     */
    protected function proxyMonitoringThresholdsToProduction(array $thresholds): void
    {
        $this->runProductionWrite(
            'runProductionMonitoringThresholds',
            [$thresholds],
            __('Change alert thresholds on production'),
            __('This changes the alert thresholds production uses for :server. Type PRODUCTION to continue for this browser session.', [
                'server' => $this->server->name,
            ]),
        );
    }

    /**
     * @param  array{cpu: float, mem: float, load: float}  $thresholds
     */
    public function runProductionMonitoringThresholds(array $thresholds): void
    {
        $this->authorize('update', $this->server);

        $serverId = $this->server->id;
        $saved = $this->withProductionMirrorClient(
            fn (ProductionApiClient $client): ?array => $client->updateServerMonitoringThresholds(
                $serverId,
                $thresholds['cpu'],
                $thresholds['mem'],
                $thresholds['load'],
            ),
            __('Metric thresholds saved on production.'),
        );

        if ($saved) {
            $this->syncThresholdSettingsFromServer();
            $this->editingThresholds = false;
        }
    }
}
