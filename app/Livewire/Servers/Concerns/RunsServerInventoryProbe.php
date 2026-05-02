<?php

namespace App\Livewire\Servers\Concerns;

use App\Livewire\Concerns\StreamsRemoteSshLivewire;
use App\Services\Servers\ServerInventoryProbeScript;
use App\Services\SshConnection;

/**
 * Refresh the server's inventory + manage probe over SSH, streaming output to the
 * remote-ssh-stream panel and persisting parsed state into server.meta.
 *
 * Composed by both WorkspaceSettings (via ManagesWorkspaceSettingsForm) and
 * WorkspaceManage. Uses StreamsRemoteSshLivewire for the live output panel.
 */
trait RunsServerInventoryProbe
{
    use StreamsRemoteSshLivewire;

    public function refreshServerInventoryDetails(): void
    {
        $this->authorize('update', $this->server);
        if (! $this->canRunInventoryProbe()) {
            $this->toastError(__('Deployers cannot run server inventory over SSH.'));

            return;
        }

        if (! $this->server->isReady() || ! $this->server->ssh_private_key || empty($this->server->ip_address)) {
            $this->toastError(__('Server must be ready with SSH before refreshing inventory.'));

            return;
        }

        $script = $this->buildInventoryShellScript();
        $timeout = $this->inventorySshTimeoutSeconds();

        $wrapped = '/bin/sh -c '.escapeshellarg($script);
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
                __('Refresh inventory'),
                sprintf('%s@%s  %s', $loginUser, $this->server->ip_address, $wrapped)
            );
            if ($i > 0) {
                $this->remoteSshStreamAppendStdout("\n\n--- ".__('Retrying as deploy SSH user')." ---\n\n");
            }

            try {
                $ssh = new SshConnection($this->server, $loginUser);
                $out = trim($ssh->execWithCallback(
                    $wrapped,
                    fn (string $chunk) => $this->remoteSshStreamAppendStdout($chunk),
                    $timeout,
                ));
                $ssh->disconnect();
                break;
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }

        if ($out === null) {
            $this->toastError($lastError !== null ? $lastError->getMessage() : __('SSH connection failed for inventory check.'));

            return;
        }

        try {
            $maxPreviewBytes = max(1024, (int) config('server_settings.inventory_package_preview_max_bytes', 16384));
            $maxExtBytes = (int) config('server_settings.inventory_extended_max_bytes', 32000);

            $meta = app(ServerInventoryProbeScript::class)->parse(
                $out,
                $this->server->meta ?? [],
                $maxPreviewBytes,
                $maxExtBytes,
            );

            $this->server->update(['meta' => $meta]);
            $this->server->refresh();

            if (method_exists($this, 'syncSettingsFormFromServer')) {
                $this->syncSettingsFormFromServer();
            }
            if (method_exists($this, 'syncExtendedServerSettingsFromServer')) {
                $this->syncExtendedServerSettingsFromServer();
            }

            $this->toastSuccess(__('Server inventory refreshed from SSH.'));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    protected function buildInventoryShellScript(): string
    {
        $previewLines = (int) config('server_settings.inventory_package_preview_lines', 80);
        $depth = (string) (($this->server->fresh()->meta ?? [])['inventory_scan_depth'] ?? 'basic');

        // Manage callers always want the extended snapshot regardless of the user's depth preference.
        $forceExtended = $this->forceExtendedInventoryProbe();

        return app(ServerInventoryProbeScript::class)->build(
            extended: $forceExtended || $depth === 'extended',
            previewLines: $previewLines,
        );
    }

    protected function inventorySshTimeoutSeconds(): int
    {
        $depth = (string) (($this->server->fresh()->meta ?? [])['inventory_scan_depth'] ?? 'basic');
        $extended = $this->forceExtendedInventoryProbe() || $depth === 'extended';

        return $extended
            ? (int) config('server_settings.inventory_ssh_timeout_extended', 180)
            : (int) config('server_settings.inventory_ssh_timeout_basic', 120);
    }

    /**
     * Subclasses can override to force the extended snapshot (Manage tabs always want it).
     */
    protected function forceExtendedInventoryProbe(): bool
    {
        return false;
    }

    protected function canRunInventoryProbe(): bool
    {
        return ! (bool) auth()->user()?->currentOrganization()?->userIsDeployer(auth()->user());
    }
}
