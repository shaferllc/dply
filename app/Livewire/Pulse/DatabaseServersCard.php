<?php

declare(strict_types=1);

namespace App\Livewire\Pulse;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Pulse card for the database (Postgres/MySQL) servers dply manages. */
class DatabaseServersCard extends ServiceServersCard
{
    protected function title(): string
    {
        return 'Database Servers';
    }

    protected function icon(): string
    {
        return 'heroicon-o-circle-stack';
    }

    protected function serverIds(): Collection
    {
        return DB::table('server_database_engines')->distinct()->pluck('server_id');
    }

    protected function serviceLabel(string $serverId): ?string
    {
        $eng = DB::table('server_database_engines')
            ->where('server_id', $serverId)
            ->orderByDesc('is_default')
            ->first();

        if (! $eng) {
            return null;
        }

        return trim(implode(' · ', array_filter([
            trim(($eng->engine ?? 'postgres').' '.($eng->version ?? '')),
            $eng->status ?? null,
            $eng->port ? 'port '.$eng->port : null,
        ])));
    }
}
