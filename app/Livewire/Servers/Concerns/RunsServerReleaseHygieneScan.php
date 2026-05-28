<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Livewire\Concerns\StreamsRemoteSshLivewire;
use App\Models\ServerRecipe;
use App\Services\Servers\ServerReleaseHygieneScript;
use App\Services\SshConnection;
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

        $sites = $this->server->sites()
            ->get(['slug', 'repository_path', 'deploy_strategy', 'releases_to_keep'])
            ->map(fn ($site): array => [
                'slug' => (string) $site->slug,
                'path' => $site->effectiveRepositoryPath(),
                'keep' => max(1, min(50, (int) ($site->releases_to_keep ?? 5))),
                'atomic' => $site->isAtomicDeploys(),
            ])
            ->values()
            ->all();

        $script = app(ServerReleaseHygieneScript::class)->build($sites);
        $wrapped = '/bin/sh -c '.escapeshellarg($script);
        $timeout = max(60, (int) config('server_settings.inventory_ssh_timeout_basic', 120));

        $deploy = trim((string) $this->server->ssh_user) ?: 'root';
        $wantRoot = (bool) config('server_settings.inventory_use_root_ssh', true);
        $fallback = (bool) config('server_settings.inventory_fallback_to_deploy_user_ssh', true);
        $candidates = [];
        if ($wantRoot && $deploy !== 'root') {
            $candidates[] = 'root';
            if ($fallback) {
                $candidates[] = $deploy;
            }
        } else {
            $candidates[] = $deploy;
        }

        $this->resetRemoteSshStreamTargets();
        $lastError = null;
        $out = null;

        foreach ($candidates as $i => $loginUser) {
            $this->remoteSshStreamSetMeta(
                __('Scan release hygiene'),
                sprintf('%s@%s  %s', $loginUser, $this->server->ip_address, $wrapped),
            );
            if ($i > 0) {
                $this->remoteSshStreamAppendStdout("\n\n--- ".__('Retrying as deploy SSH user')." ---\n\n");
            }

            try {
                $ssh = new SshConnection($this->server, $loginUser);
                $out = trim($ssh->execWithCallback(
                    $wrapped,
                    fn (string $chunk): mixed => $this->remoteSshStreamAppendStdout($chunk),
                    $timeout,
                ));
                $ssh->disconnect();
                break;
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }

        if ($out === null) {
            $this->toastError($lastError !== null ? $lastError->getMessage() : __('SSH connection failed for hygiene scan.'));

            return;
        }

        try {
            $meta = app(ServerReleaseHygieneScript::class)->parse($out, $this->server->meta ?? []);
            $this->server->update(['meta' => $meta]);
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
