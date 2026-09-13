<?php

declare(strict_types=1);

namespace App\Modules\Queue\Jobs;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

/**
 * Turn a freshly provisioned server into a proven fleet host, unattended.
 *
 * Dispatched when provisioning finishes on a server that was created to be one
 * (`meta.queue_fleet_host.pending`, written by `dply:queue:fleet-host-create`).
 * It runs the same two commands an operator would — so there is one definition
 * of "opted in" and one of "proven" — and records how each went on the server.
 */
class PrepareFleetHostJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    /** Docker install, then a smoke test that may build an image (1800s ceiling). */
    public int $timeout = 2700;

    public function __construct(public string $serverId)
    {
        $this->onQueue('dply-control');
    }

    public function uniqueId(): string
    {
        return $this->serverId;
    }

    public function handle(): void
    {
        $server = Server::query()->find($this->serverId);
        $pending = $server instanceof Server ? data_get($server->meta, 'queue_fleet_host.pending') : null;

        if (! is_array($pending)) {
            return;
        }

        // Installs Docker, then opts in — and refuses to opt in without Docker.
        $optIn = Artisan::call('dply:queue:fleet-host', [
            'server' => (string) $server->id,
            '--capacity' => (int) ($pending['capacity_mib'] ?? 3072),
        ]);
        $this->record($server, 'opt_in', $optIn, Artisan::output());

        if ($optIn !== 0) {
            return;
        }

        // Proving needs a site to build an image from; without one the host is
        // opted in and the smoke test waits for an operator.
        $siteId = (string) ($pending['smoke_site_id'] ?? '');
        if ($siteId !== '') {
            $smoke = Artisan::call('dply:queue:fleet-smoke', ['server' => (string) $server->id, '--site' => $siteId]);
            $this->record($server, 'smoke', $smoke, Artisan::output());
        }

        $server->refresh();
        $meta = is_array($server->meta) ? $server->meta : [];
        unset($meta['queue_fleet_host']['pending']);
        $server->forceFill(['meta' => $meta])->save();
    }

    /**
     * Each command writes the server's meta itself, so read it fresh.
     */
    private function record(Server $server, string $step, int $exitCode, string $output): void
    {
        $server->refresh();
        $meta = is_array($server->meta) ? $server->meta : [];
        data_set($meta, 'queue_fleet_host.preparation.'.$step, [
            'ok' => $exitCode === 0,
            'at' => now()->toIso8601String(),
            'output' => mb_substr(trim($output), -1500),
        ]);
        $server->forceFill(['meta' => $meta])->save();
    }
}
