<?php

declare(strict_types=1);

namespace App\Livewire\Pulse;

use App\Models\Server;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;
use Livewire\Attributes\Lazy;

/**
 * Base for the per-service Pulse cards (Redis / Database / Workers).
 *
 * Unlike the stock Pulse Servers card — which reads metrics recorded by a
 * `pulse:check` daemon running ON each host — these cards surface the metrics
 * dply already collects centrally in `server_metric_snapshots`. That lets us
 * show infrastructure boxes (Redis, Postgres, workers) that do NOT run the dply
 * app, and therefore can't run Pulse's own recorder. Presentation only: no new
 * collection, no agents on those hosts.
 */
#[Lazy]
abstract class ServiceServersCard extends Card
{
    /** Card title shown in the header. */
    abstract protected function title(): string;

    /** Heroicon blade component name, e.g. "heroicon-o-circle-stack". */
    abstract protected function icon(): string;

    /** Server ids belonging to this service role. @return Collection<int, string> */
    abstract protected function serverIds(): Collection;

    /** Short service-specific badge for a server (engine/version/role). */
    abstract protected function serviceLabel(string $serverId): ?string;

    public function render(): Renderable
    {
        $ids = $this->serverIds()->all();

        $servers = Server::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get(['id', 'name', 'ip_address'])
            ->map(function (Server $server): object {
                $snap = DB::table('server_metric_snapshots')
                    ->where('server_id', $server->id)
                    ->latest('captured_at')
                    ->first();

                $p = $snap && $snap->payload
                    ? (array) json_decode($snap->payload, true)
                    : [];

                $capturedAt = $snap?->captured_at ? Carbon::parse($snap->captured_at) : null;

                return (object) [
                    'name' => $server->name,
                    'ip' => $server->ip_address,
                    'service' => $this->serviceLabel($server->id),
                    'cpu' => $p['cpu_pct'] ?? null,
                    'memory' => $p['mem_pct'] ?? null,
                    'disk' => $p['disk_pct'] ?? null,
                    'load' => $p['load_1m'] ?? null,
                    'captured_at' => $capturedAt,
                    'stale' => $capturedAt === null || $capturedAt->lt(now()->subMinutes(10)),
                ];
            });

        return View::make('pulse.service-servers-card', [
            'title' => $this->title(),
            'icon' => $this->icon(),
            'servers' => $servers,
            'cols' => $this->cols,
            'rows' => $this->rows,
            'class' => $this->class,
        ]);
    }
}
