<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Livewire\Concerns\StreamsRemoteSshLivewire;
use App\Services\Servers\ServerSecurityDigestScanner;

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

        $this->resetRemoteSshStreamTargets();

        try {
            // Shared scan path: connects, parses, persists meta, and fires
            // transition-aware posture notifications. Streams stdout to the UI.
            app(ServerSecurityDigestScanner::class)->scanAndNotify(
                $this->server,
                auth()->user(),
                fn (string $chunk): mixed => $this->remoteSshStreamAppendStdout($chunk),
            );
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
