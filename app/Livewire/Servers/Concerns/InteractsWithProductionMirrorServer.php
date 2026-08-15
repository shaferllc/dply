<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Models\ProductionDataConnection;
use App\Models\Server;
use App\Services\ProductionData\ProductionApiClient;
use App\Services\ProductionData\ProductionApiException;
use App\Services\ProductionData\ProductionDataMirror;
use App\Services\ProductionData\ProductionServerMaterializer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use Throwable;

/**
 * Server-workspace half of the production-data mirror.
 *
 * A mirrored server row is a real production host that this control plane holds
 * no SSH key for: `serverOpsReady()` is false and always will be. That gate is
 * correct for anything that would SSH from here, but it also means every
 * SSH-backed workspace page reads a live host as un-provisioned. This concern
 * gives those pages the third answer — the host *is* provisioned, we simply ask
 * the control plane that owns it — by pulling state over the REST API and
 * routing writes back to it.
 *
 * Writes still pass the standard production-write confirmation, so the first
 * one per browser session has to be typed out.
 *
 * @phpstan-require-extends Component
 */
trait InteractsWithProductionMirrorServer
{
    /** How long a failed monitoring pull parks further attempts. */
    private const MONITORING_BACKOFF_SECONDS = 60;

    /** Guards against re-pulling within a single request (render + action). */
    private bool $productionMirrorMonitoringPulled = false;

    /**
     * True when this row mirrors a production host *and* the connection that
     * created it is still bound — an expired connection falls back to the plain
     * local behaviour rather than pretending it can reach anything.
     */
    protected function isProductionMirrorServer(): bool
    {
        return $this->server instanceof Server
            && data_get($this->server->meta, 'production_data_mirror') === true
            && $this->productionMirrorConnection() !== null;
    }

    protected function productionMirrorConnection(): ?ProductionDataConnection
    {
        return app(ProductionDataMirror::class)->connectionFor(Auth::user());
    }

    /**
     * Pull agent state + samples for this host and materialize them locally.
     *
     * Cached by ProductionDataMirror (default 20s), so the Metrics page's own
     * polling does not turn into one upstream request per poll. `force` drops
     * that entry first — used right after a write, where the point is to see
     * what changed.
     */
    protected function refreshProductionMirrorMonitoring(bool $force = false): void
    {
        if (! $this->isProductionMirrorServer()) {
            return;
        }
        if ($this->productionMirrorMonitoringPulled && ! $force) {
            return;
        }

        $connection = $this->productionMirrorConnection();
        if ($connection === null) {
            return;
        }

        $mirror = app(ProductionDataMirror::class);
        $cacheKey = 'servers.'.$this->server->id.'.metrics.v1';
        // A remote that predates these routes 404s every time. Without a
        // backoff the page would re-ask on every render and poll, so a failure
        // parks the pull briefly — `force` (a write just landed) ignores it.
        $backoffKey = 'production-mirror-metrics-backoff:'.$connection->id.':'.$this->server->id;

        if ($force) {
            $mirror->forget($connection, $cacheKey);
            Cache::forget($backoffKey);
        } elseif (Cache::has($backoffKey)) {
            $this->productionMirrorMonitoringPulled = true;

            return;
        }

        try {
            $metrics = $mirror->remember(
                $connection,
                $cacheKey,
                fn (ProductionApiClient $client): array => $client->serverMetrics($this->server->id),
            );

            $this->server = app(ProductionServerMaterializer::class)
                ->syncMonitoring($this->server, $metrics);
            $this->productionMirrorMonitoringPulled = true;
        } catch (ProductionApiException $e) {
            // A 404 here is the ordinary "remote predates this endpoint" case,
            // and a hard failure would take the whole workspace page down, so
            // the page falls back to whatever was last mirrored.
            $this->productionMirrorMonitoringPulled = true;
            Cache::put($backoffKey, true, now()->addSeconds(self::MONITORING_BACKOFF_SECONDS));
            report($e);
        } catch (Throwable $e) {
            $this->productionMirrorMonitoringPulled = true;
            Cache::put($backoffKey, true, now()->addSeconds(self::MONITORING_BACKOFF_SECONDS));
            report($e);
        }
    }

    /**
     * Run a write against the control plane that owns the host, then re-pull so
     * the page shows the remote's new state rather than an optimistic guess.
     *
     * @param  callable(ProductionApiClient): mixed  $callback
     */
    protected function withProductionMirrorClient(callable $callback, ?string $successMessage = null): bool
    {
        $connection = $this->productionMirrorConnection();
        if ($connection === null) {
            $this->toastError(__('Production connection expired. Connect again.'));

            return false;
        }

        try {
            app(ProductionDataMirror::class)->withClient($connection, $callback);
        } catch (ProductionApiException $e) {
            $this->toastError($e->getMessage());

            return false;
        } catch (Throwable $e) {
            $this->toastError($e->getMessage());

            return false;
        }

        if ($successMessage !== null) {
            $this->toastSuccess($successMessage);
        }

        $this->refreshProductionMirrorMonitoring(force: true);

        return true;
    }
}
