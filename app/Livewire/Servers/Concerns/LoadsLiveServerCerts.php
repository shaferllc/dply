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
    /** @var list<array<string, mixed>> */
    public array $liveCerts = [];

    public bool $liveCertsLoaded = false;

    public bool $liveCertsScanning = false;

    public bool $liveCertsUnreadable = false;

    public ?string $liveCertsError = null;

    public ?string $liveCertsScannedAtIso = null;

    /** Fired from wire:init (and the Rescan button via refreshLiveCerts). */
    public function loadLiveCerts(bool $forceFresh = false): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->liveCertsError = __('Provisioning and SSH must be ready before scanning live certificates.');
            $this->liveCertsLoaded = true;
            $this->liveCertsScanning = false;

            return;
        }

        $aggregator = app(WebserverCertsAggregator::class);
        $cached = $forceFresh ? null : $aggregator->cached($this->server);
        if ($cached !== null) {
            $this->applyLiveCertResult($cached);

            return;
        }

        // No fresh cache → queue an async SSH sweep and let the blade poll.
        $aggregator->dispatchScan($this->server, $forceFresh);
        $this->liveCertsScanning = true;
        $this->liveCertsLoaded = false;
        $this->liveCertsError = null;
    }

    /** Driven by wire:poll while a scan is in flight; resolves once the job caches a result. */
    public function pollLiveCerts(): void
    {
        if (! $this->liveCertsScanning) {
            return;
        }

        $cached = app(WebserverCertsAggregator::class)->cached($this->server);
        if ($cached !== null) {
            $this->applyLiveCertResult($cached);
        }
    }

    public function refreshLiveCerts(): void
    {
        $this->loadLiveCerts(forceFresh: true);
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
        $this->liveCertsError = null;
    }
}
