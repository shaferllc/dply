<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\Servers\ServerInventoryProbeScript;
use App\Services\SshConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Background refresh of the server's inventory + manage probe.
 *
 * Dispatched after consequential manage actions (apt upgrade, restart,
 * unattended-upgrades change) so that the next page render shows fresh
 * meta without the user having to click Refresh state.
 *
 * Runs the same script + parser as the synchronous probe in
 * RunsServerInventoryProbe but writes silently to server.meta.
 */
class RefreshServerInventoryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 240;

    public function __construct(public string $serverId) {}

    public function handle(ServerInventoryProbeScript $svc): void
    {
        $server = Server::query()->find($this->serverId);
        if ($server === null || ! $server->isReady() || empty($server->ip_address) || ! $server->hasAnySshPrivateKey()) {
            return;
        }

        $script = $svc->build(
            extended: true,
            previewLines: (int) config('server_settings.inventory_package_preview_lines', 80),
        );
        $wrapped = '/bin/sh -c '.escapeshellarg($script);

        $deploy = trim((string) $server->ssh_user) ?: 'root';
        $candidates = [];
        if ((bool) config('server_settings.inventory_use_root_ssh', true) && $deploy !== 'root') {
            $candidates[] = 'root';
            if ((bool) config('server_settings.inventory_fallback_to_deploy_user_ssh', true)) {
                $candidates[] = $deploy;
            }
        } else {
            $candidates[] = $deploy;
        }

        $timeout = (int) config('server_settings.inventory_ssh_timeout_extended', 180);

        $out = null;
        foreach ($candidates as $loginUser) {
            try {
                $ssh = new SshConnection($server, $loginUser);
                $out = trim($ssh->execWithCallback(
                    $wrapped,
                    fn (string $chunk) => null,
                    $timeout,
                ));
                $ssh->disconnect();
                break;
            } catch (\Throwable) {
                // Try the next candidate.
            }
        }

        if ($out === null || $out === '') {
            return;
        }

        $maxPreviewBytes = max(1024, (int) config('server_settings.inventory_package_preview_max_bytes', 16384));
        $maxExtBytes = (int) config('server_settings.inventory_extended_max_bytes', 32000);

        $meta = $svc->parse($out, $server->meta ?? [], $maxPreviewBytes, $maxExtBytes);
        $server->update(['meta' => $meta]);
    }
}
