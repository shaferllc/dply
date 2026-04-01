<?php

namespace App\Livewire\Backups;

use App\Models\BackupConfiguration;
use App\Models\Site;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Files extends Component
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
            ->with(['server', 'workspace.runbooks'])
            ->orderBy('name')
            ->get();

        $storageDestinations = $user->backupConfigurations()
            ->orderBy('name')
            ->get(['id', 'name', 'provider']);

        return view('livewire.backups.files', [
            'organization' => $org,
            'sites' => $sites,
            'storageDestinations' => $storageDestinations,
            'providerLabels' => collect(BackupConfiguration::providers())
                ->mapWithKeys(fn (string $provider) => [$provider => BackupConfiguration::labelForProvider($provider)]),
        ]);
    }
}
