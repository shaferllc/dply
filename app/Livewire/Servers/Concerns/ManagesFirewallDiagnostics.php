<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Services\Servers\ServerFirewallProvisioner;
use App\Services\SshConnection;
use Illuminate\Support\Str;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesFirewallDiagnostics
{
    public ?string $ufw_status_text = null;

    public bool $ufw_diagnostics_modal_open = false;

    public ?string $ufw_diagnostics_text = null;

    public function refreshUfwStatus(ServerFirewallProvisioner $firewall): void
    {
        $this->authorize('update', $this->server);
        try {
            $this->server->refresh();
            $this->ufw_status_text = $firewall->status($this->server);
            $this->emitPanelEvent(
                __('UFW status refreshed.'),
                array_merge(
                    ['> ufw status verbose against '.$this->server->getSshConnectionString().' …'],
                    $this->splitOutputForBanner((string) $this->ufw_status_text),
                ),
                'completed',
            );
            $this->toastSuccess(__('Refreshed UFW status — see the banner for the host output.'));
        } catch (\Throwable $e) {
            $this->ufw_status_text = null;
            $this->emitPanelEvent(
                __('UFW status fetch failed.'),
                [
                    '> ufw status verbose against '.$this->server->getSshConnectionString().' …',
                    '> ERROR: '.Str::limit(trim($e->getMessage()), 800),
                ],
                'failed',
            );
            $this->toastError($e->getMessage());
        }
    }

    /**
     * Re-run just the listening-ports portion of the inventory probe (`ss -lntpH`) and stamp
     * the result onto `meta.manage_listening_ports` so the table on the Rules tab refreshes.
     * Tries root first then falls back to the deploy user, mirroring the inventory probe.
     */
    public function refreshListeningPorts(): void
    {
        $this->authorize('update', $this->server);
        if (! $this->opsReady()) {
            $this->toastError(__('Server must be ready with SSH before refreshing listening ports.'));

            return;
        }

        $this->server->refresh();

        $command = '/bin/sh -c '.escapeshellarg(
            '(sudo -n ss -lntpH 2>/dev/null || ss -lntpH 2>/dev/null) | head -n 60'
        );

        $deploy = trim((string) $this->server->ssh_user) ?: 'root';
        $candidates = [];
        if ((bool) config('server_settings.inventory_use_root_ssh', true) && $deploy !== 'root') {
            $candidates[] = 'root';
            if ((bool) config('server_settings.inventory_fallback_to_deploy_user_ssh', true)) {
                $candidates[] = $deploy;
            }
        } else {
            $candidates[] = $deploy;
        }

        $out = null;
        $lastError = null;
        foreach ($candidates as $loginUser) {
            try {
                $ssh = new SshConnection($this->server, $loginUser);
                $out = trim($ssh->exec($command, 30));
                $ssh->disconnect();
                break;
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }

        if ($out === null) {
            $this->toastError($lastError !== null ? $lastError->getMessage() : __('SSH connection failed.'));

            return;
        }

        if (strlen($out) > 16384) {
            $out = substr($out, 0, 16384)."\n[dply] truncated";
        }

        $meta = $this->server->meta ?? [];
        if ($out !== '') {
            $meta['manage_listening_ports'] = $out;
        } else {
            unset($meta['manage_listening_ports']);
        }
        $this->server->update(['meta' => $meta]);
        $this->server->refresh();

        $this->toastSuccess(__('Listening ports refreshed.'));
    }

    public function runFirewallDiagnostics(ServerFirewallProvisioner $firewall): void
    {
        $this->authorize('update', $this->server);
        try {
            $this->server->refresh();
            $this->ufw_diagnostics_text = $firewall->diagnostics($this->server);
            $this->emitPanelEvent(
                __('Firewall diagnostics complete.'),
                array_merge(
                    ['> ufw status verbose · numbered · ss -ltn · iptables -L INPUT against '.$this->server->getSshConnectionString().' …'],
                    $this->splitOutputForBanner((string) $this->ufw_diagnostics_text, 400),
                ),
                'completed',
            );
            $this->toastSuccess(__('Diagnostics complete — see the banner for the full output.'));
        } catch (\Throwable $e) {
            $this->emitPanelEvent(
                __('Firewall diagnostics failed.'),
                [
                    '> diagnostics against '.$this->server->getSshConnectionString().' …',
                    '> ERROR: '.Str::limit(trim($e->getMessage()), 800),
                ],
                'failed',
            );
            $this->toastError($e->getMessage());
        }
    }

    public function closeFirewallDiagnostics(): void
    {
        $this->ufw_diagnostics_modal_open = false;
    }
}
