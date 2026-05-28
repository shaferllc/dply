<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Livewire\Concerns\StreamsRemoteSshLivewire;
use App\Services\Servers\ServerSecurityDigestScript;
use App\Services\SshConnection;

trait RunsServerSecurityDigestScan
{
    use StreamsRemoteSshLivewire;

    public function refreshSecurityDigestScan(): void
    {
        $this->authorize('update', $this->server);
        if (! $this->canRunSecurityDigestScan()) {
            $this->toastError(__('Deployers cannot run security digest scans over SSH.'));

            return;
        }

        if (! $this->server->isReady() || ! $this->server->ssh_private_key || empty($this->server->ip_address)) {
            $this->toastError(__('Server must be ready with SSH before scanning security digest.'));

            return;
        }

        $script = app(ServerSecurityDigestScript::class)->build();
        $wrapped = '/bin/sh -c '.escapeshellarg($script);
        $timeout = max(60, (int) config('server_settings.inventory_ssh_timeout_basic', 120));
        $deploy = trim((string) $this->server->ssh_user) ?: 'root';
        $wantRoot = (bool) config('server_settings.inventory_use_root_ssh', true);
        $fallback = (bool) config('server_settings.inventory_fallback_to_deploy_user_ssh', true);
        $candidates = $wantRoot && $deploy !== 'root' ? array_filter(['root', $fallback ? $deploy : null]) : [$deploy];
        $candidates = array_values(array_filter($candidates));

        $this->resetRemoteSshStreamTargets();
        $lastError = null;
        $out = null;

        foreach ($candidates as $loginUser) {
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
            $this->toastError($lastError !== null ? $lastError->getMessage() : __('SSH connection failed for security digest.'));

            return;
        }

        try {
            $meta = app(ServerSecurityDigestScript::class)->parse($out, $this->server->meta ?? []);
            $this->server->update(['meta' => $meta]);
            $this->server->refresh();
            $this->toastSuccess(__('Security digest scan completed.'));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    protected function canRunSecurityDigestScan(): bool
    {
        return ! (bool) auth()->user()?->currentOrganization()?->userIsDeployer(auth()->user());
    }
}
