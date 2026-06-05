<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Services\Servers\WebserverCertsAggregator;
use Carbon\CarbonImmutable;

/**
 * Shared "live on-disk TLS certificates" loader for the server surfaces that
 * render the cross-engine cert sweep (the webserver Health tab and the cert
 * inventory page). One mechanism: read the cached sweep, and when it's missing
 * or a rescan is requested, dispatch {@see \App\Jobs\ScanServerLiveCertsJob} and
 * poll for the result — the SSH probe never runs in the request.
 *
 * Hosts must expose a public `Server $server`, the `serverOpsReady()` guard, and
 * Livewire's `authorize()` (both come from InteractsWithServerWorkspace). The
 * blade renders {@see resources/views/livewire/servers/partials/_live-server-certs.blade.php}.
 */
trait LoadsLiveServerCerts
{
    /**
     * How long the blade polls for a queued scan's result before giving up and
     * showing the timed-out/Retry state instead of spinning forever. Sized to
     * comfortably cover the job's own 90s timeout + queue latency without
     * leaving the operator staring at a spinner if the worker is down.
     */
    private const LIVE_CERTS_POLL_INTERVAL_SECONDS = 4;

    private const LIVE_CERTS_POLL_TIMEOUT_SECONDS = 60;

    /** @var list<array<string, mixed>> */
    public array $liveCerts = [];

    public bool $liveCertsLoaded = false;

    public bool $liveCertsScanning = false;

    public bool $liveCertsUnreadable = false;

    public bool $liveCertsTimedOut = false;

    public ?string $liveCertsError = null;

    public ?string $liveCertsScannedAtIso = null;

    /** Number of poll ticks elapsed for the in-flight scan; drives the client-side timeout. */
    public int $liveCertsPollCount = 0;

    /** Fired from wire:init (and the Rescan button via refreshLiveCerts). */
    public function loadLiveCerts(bool $forceFresh = false): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->liveCertsError = __('Provisioning and SSH must be ready before scanning live certificates.');
            $this->liveCertsLoaded = true;
            $this->liveCertsScanning = false;
            $this->liveCertsTimedOut = false;

            return;
        }

        $aggregator = app(WebserverCertsAggregator::class);

        // On a plain load (wire:init), serve an existing cached result immediately
        // and never kick off a fresh scan — only Rescan (forceFresh) re-dispatches.
        $cached = $forceFresh ? null : $aggregator->cached($this->server);
        if ($cached !== null) {
            $this->applyLiveCertResult($cached);

            return;
        }

        // No fresh cache → queue an async SSH sweep and let the blade poll.
        $aggregator->dispatchScan($this->server, $forceFresh);
        $this->liveCertsScanning = true;
        $this->liveCertsLoaded = false;
        $this->liveCertsTimedOut = false;
        $this->liveCertsError = null;
        $this->liveCertsPollCount = 0;
    }

    /**
     * Driven by wire:poll while a scan is in flight; resolves once the job caches
     * a result, or stops and flips to the timed-out state once the poll budget is
     * exhausted so the panel never spins indefinitely (e.g. when the worker that
     * runs ScanServerLiveCertsJob is down).
     */
    public function pollLiveCerts(): void
    {
        if (! $this->liveCertsScanning) {
            return;
        }

        $cached = app(WebserverCertsAggregator::class)->cached($this->server);
        if ($cached !== null) {
            $this->applyLiveCertResult($cached);

            return;
        }

        $this->liveCertsPollCount++;
        if ($this->liveCertsPollCount >= $this->liveCertsMaxPolls()) {
            $this->liveCertsScanning = false;
            $this->liveCertsTimedOut = true;
            $this->liveCertsLoaded = false;
        }
    }

    public function refreshLiveCerts(): void
    {
        $this->loadLiveCerts(forceFresh: true);
    }

    /** Poll-interval the blade should use (seconds). */
    public function liveCertsPollInterval(): int
    {
        return self::LIVE_CERTS_POLL_INTERVAL_SECONDS;
    }

    private function liveCertsMaxPolls(): int
    {
        return (int) ceil(self::LIVE_CERTS_POLL_TIMEOUT_SECONDS / self::LIVE_CERTS_POLL_INTERVAL_SECONDS);
    }

    /** @param  array{certs: list<array<string, mixed>>, scanned_at: ?CarbonImmutable, unreadable: bool}  $result */
    private function applyLiveCertResult(array $result): void
    {
        $this->liveCerts = array_map(function (array $row): array {
            $row['expires_at'] = $row['expires_at'] instanceof CarbonImmutable
                ? $row['expires_at']->toIso8601String()
                : null;

            return $row;
        }, $result['certs']);
        $this->liveCertsScannedAtIso = $result['scanned_at'] instanceof CarbonImmutable
            ? $result['scanned_at']->toIso8601String()
            : null;
        $this->liveCertsUnreadable = (bool) $result['unreadable'];
        $this->liveCertsLoaded = true;
        $this->liveCertsScanning = false;
        $this->liveCertsTimedOut = false;
        $this->liveCertsError = null;
        $this->liveCertsPollCount = 0;
    }
}
