<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Jobs\ScanServerReleaseHygieneJob;
use App\Models\ServerRecipe;
use App\Support\ServerReleaseHygieneScanStatus;
use Illuminate\Support\Facades\Auth;

/**
 * SSH scan for release folders, Laravel log sizes, and failed queue jobs.
 *
 * The scan runs in {@see ScanServerReleaseHygieneJob} and the UI polls for the
 * result — SSH never runs in the request, so the button can't get stuck when the
 * scan outlives PHP's request cap.
 */
trait RunsServerReleaseHygieneScan
{
    /** Poll cadence + budget for the queued scan; budget comfortably clears the job's 180s timeout + queue latency. */
    private const HYGIENE_SCAN_POLL_INTERVAL_SECONDS = 2;

    private const HYGIENE_SCAN_POLL_TIMEOUT_SECONDS = 210;

    public bool $hygieneScanning = false;

    public bool $hygieneScanTimedOut = false;

    public ?string $hygieneScanError = null;

    /** @var list<array{t: int, line: string}> */
    public array $hygieneScanProgress = [];

    public int $hygieneScanPollCount = 0;

    public function refreshReleaseHygieneScan(): void
    {
        $this->authorize('update', $this->server);
        if (! $this->canRunReleaseHygieneScan()) {
            $this->toastError(__('Deployers cannot run release hygiene scans over SSH.'));

            return;
        }

        if (! $this->server->isReady() || ! $this->server->ssh_private_key || empty($this->server->ip_address)) {
            $this->toastError(__('Server must be ready with SSH before scanning release hygiene.'));

            return;
        }

        // Queue the SSH scan and let the blade poll for the result. The scan can
        // take ~120s — far past PHP's 30s request cap — so running it inline left
        // the button stuck on "Scanning…" when the request died mid-scan.
        $serverId = (string) $this->server->id;
        ServerReleaseHygieneScanStatus::reset($serverId);
        ScanServerReleaseHygieneJob::dispatch($serverId, (string) Auth::id());

        $this->hygieneScanning = true;
        $this->hygieneScanTimedOut = false;
        $this->hygieneScanError = null;
        $this->hygieneScanPollCount = 0;
        $this->hygieneScanProgress = [];
    }

    /**
     * Driven by wire:poll while a scan is in flight; resolves once the job writes a
     * result, or flips to the timed-out state once the poll budget is exhausted so
     * the panel never spins forever (e.g. when the scan worker is down).
     */
    public function pollReleaseHygieneScan(): void
    {
        if (! $this->hygieneScanning) {
            return;
        }

        $serverId = (string) $this->server->id;
        $this->hygieneScanProgress = ServerReleaseHygieneScanStatus::progress($serverId);

        $result = ServerReleaseHygieneScanStatus::result($serverId);
        if ($result !== null) {
            // Capture the complete frame set before resolving — the job writes the
            // result only after its final progress line — so the panel can replay them.
            $this->hygieneScanProgress = ServerReleaseHygieneScanStatus::progress($serverId);
            ServerReleaseHygieneScanStatus::reset($serverId);

            $this->hygieneScanning = false;
            $this->hygieneScanPollCount = 0;
            $this->server->refresh();

            if ($result['ok']) {
                $this->toastSuccess(__('Release hygiene scan completed.'));
            } else {
                $this->hygieneScanError = $result['error'] ?: __('Release hygiene scan failed.');
                $this->toastError($this->hygieneScanError);
            }

            return;
        }

        $this->hygieneScanPollCount++;
        if ($this->hygieneScanPollCount >= $this->hygieneScanMaxPolls()) {
            $this->hygieneScanning = false;
            $this->hygieneScanTimedOut = true;
        }
    }

    /** Poll-interval the blade should use (seconds). */
    public function hygieneScanPollInterval(): int
    {
        return self::HYGIENE_SCAN_POLL_INTERVAL_SECONDS;
    }

    private function hygieneScanMaxPolls(): int
    {
        return (int) ceil(self::HYGIENE_SCAN_POLL_TIMEOUT_SECONDS / self::HYGIENE_SCAN_POLL_INTERVAL_SECONDS);
    }

    public function installPruneSavedCommand(): void
    {
        $this->authorize('update', $this->server);
        if (! $this->canRunReleaseHygieneScan()) {
            $this->toastError(__('Deployers cannot add saved commands on this server.'));

            return;
        }

        $config = (array) config('server_release_hygiene.prune_saved_command', []);
        $name = (string) ($config['name'] ?? 'Prune atomic releases');
        $script = (string) ($config['script'] ?? '');

        if ($script === '') {
            $this->toastError(__('Prune command template is not configured.'));

            return;
        }

        if ($this->server->recipes()->where('name', $name)->exists()) {
            $this->toastSuccess(__('Prune saved command is already on this server — open Run to execute it.'));

            return;
        }

        ServerRecipe::query()->create([
            'server_id' => $this->server->id,
            'user_id' => Auth::id(),
            'name' => $name,
            'script' => $script,
        ]);

        $this->toastSuccess(__('Prune saved command added — open Run to review or execute it.'));
    }

    protected function canRunReleaseHygieneScan(): bool
    {
        return ! (bool) auth()->user()?->currentOrganization()?->userIsDeployer(auth()->user());
    }
}
