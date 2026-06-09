<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Livewire\Concerns\StreamsRemoteSshLivewire;
use App\Models\ServerRecipe;
use App\Services\Servers\ServerReleaseHygieneScanner;
use Illuminate\Support\Facades\Auth;

/**
 * SSH scan for release folders, Laravel log sizes, and failed queue jobs.
 */
trait RunsServerReleaseHygieneScan
{
    use StreamsRemoteSshLivewire;

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

        $this->resetRemoteSshStreamTargets();

        try {
            // Shared scan path: connects, parses, persists meta, and fires
            // transition-aware posture notifications. Streams stdout to the UI.
            app(ServerReleaseHygieneScanner::class)->scanAndNotify(
                $this->server,
                auth()->user(),
                fn (string $chunk): mixed => $this->remoteSshStreamAppendStdout($chunk),
            );
            $this->server->refresh();
            $this->toastSuccess(__('Release hygiene scan completed.'));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
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
