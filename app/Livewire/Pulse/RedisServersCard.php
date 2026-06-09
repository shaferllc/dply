<?php

declare(strict_types=1);

namespace App\Livewire\Pulse;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Pulse card for the cache/Redis servers dply manages. */
class RedisServersCard extends ServiceServersCard
{
    protected function title(): string
    {
        return 'Redis Servers';
    }

    protected function icon(): string
    {
        return 'heroicon-o-server-stack';
    }

    protected function serverIds(): Collection
    {
        return DB::table('server_cache_services')->distinct()->pluck('server_id');
    }

    protected function serviceLabel(string $serverId): ?string
    {
        $svc = DB::table('server_cache_services')
            ->where('server_id', $serverId)
            ->orderByDesc('updated_at')
            ->first();

        if (! $svc) {
            return null;
        }

        return trim(implode(' · ', array_filter([
            trim(($svc->engine ?? 'redis').' '.($svc->version ?? '')),
            $svc->status ?? null,
            $svc->port ? 'port '.$svc->port : null,
        ])));
    }
}
