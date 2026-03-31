<?php

namespace App\Livewire\Backups;

use App\Models\BackupConfiguration;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseBackup;
use App\Models\Site;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Databases extends Component
{
    public function render(): View
    {
        $org = auth()->user()->currentOrganization();
        if (! $org) {
            abort(403, 'Select an organization first.');
        }

        $this->authorize('viewAny', Site::class);

        $serverIds = $org->servers()->pluck('id');
        $user = auth()->user();

        /** @var Collection<int, Site> $sites */
        $sites = Site::query()
            ->whereIn('server_id', $serverIds)
            ->with('server')
            ->orderBy('name')
            ->get();

        $storageDestinations = $user->backupConfigurations()
            ->orderBy('name')
            ->get(['id', 'name', 'provider']);

        $databaseCounts = ServerDatabase::query()
            ->whereIn('server_id', $serverIds)
            ->selectRaw('server_id, count(*) as aggregate')
            ->groupBy('server_id')
            ->pluck('aggregate', 'server_id');

        $latestBackups = ServerDatabaseBackup::query()
            ->whereHas('serverDatabase', fn ($query) => $query->whereIn('server_id', $serverIds))
            ->with('serverDatabase:id,server_id,name')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy(fn (ServerDatabaseBackup $backup) => (string) $backup->serverDatabase?->server_id)
            ->map(fn ($group) => $group->first());

        return view('livewire.backups.databases', [
            'organization' => $org,
            'sites' => $sites,
            'storageDestinations' => $storageDestinations,
            'databaseCounts' => $databaseCounts,
            'latestBackups' => $latestBackups,
            'providerLabels' => collect(BackupConfiguration::providers())
                ->mapWithKeys(fn (string $provider) => [$provider => BackupConfiguration::labelForProvider($provider)]),
        ]);
    }
}
