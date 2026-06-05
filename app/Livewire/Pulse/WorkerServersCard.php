<?php

declare(strict_types=1);

namespace App\Livewire\Pulse;

use App\Models\Server;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** Pulse card for the queue-worker servers (identified by pool_role). */
class WorkerServersCard extends ServiceServersCard
{
    protected function title(): string
    {
        return 'Worker Servers';
    }

    protected function icon(): string
    {
        return 'heroicon-o-cpu-chip';
    }

    protected function serverIds(): Collection
    {
        return Server::query()->whereNotNull('pool_role')->pluck('id');
    }

    protected function serviceLabel(string $serverId): ?string
    {
        $role = Server::query()->whereKey($serverId)->value('pool_role');

        return $role ? Str::headline((string) $role).' worker' : 'Worker';
    }
}
