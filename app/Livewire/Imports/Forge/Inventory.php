<?php

declare(strict_types=1);

namespace App\Livewire\Imports\Forge;

use App\Jobs\Imports\SyncForgeInventoryJob;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\ForgeServer;
use App\Models\ImportServerMigration;
use App\Models\ProviderCredential;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Read-only inventory of the current organization's Laravel Forge servers.
 * Same shape as App\Livewire\Imports\Ploi\Inventory — kept parallel for v1
 * until the b→c table promotion folds both sources into a shared template.
 */
#[Layout('layouts.app')]
class Inventory extends Component
{
    use DispatchesToastNotifications;

    public const SYNC_STALENESS_SECONDS = 300;

    public bool $showRemoved = false;

    public function mount(): void
    {
        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            return;
        }
        foreach ($this->credentials() as $credential) {
            $newest = ForgeServer::query()
                ->where('provider_credential_id', $credential->id)
                ->max('last_synced_at');

            $stale = $newest === null
                || $this->parseTimestamp($newest)?->diffInSeconds(now(), absolute: true) > self::SYNC_STALENESS_SECONDS;

            if ($stale) {
                SyncForgeInventoryJob::dispatch($credential->id);
            }
        }
    }

    public function refresh(): void
    {
        $count = 0;
        foreach ($this->credentials() as $credential) {
            SyncForgeInventoryJob::dispatch($credential->id);
            $count++;
        }
        if ($count === 0) {
            $this->toastError(__('Connect a Forge credential first.'));

            return;
        }
        $this->toastSuccess(__('Refresh queued. Inventory will update shortly.'));
    }

    public function render(): View
    {
        $credentials = $this->credentials();
        $servers = $this->servers($credentials);
        $activeMigrations = $this->activeMigrationsForServers($servers);

        return view('livewire.imports.forge.inventory', [
            'credentials' => $credentials,
            'servers' => $servers,
            'hasCredentials' => $credentials->isNotEmpty(),
            'activeMigrations' => $activeMigrations,
            'activeMigrationCount' => $activeMigrations->count(),
        ]);
    }

    /**
     * @return Collection<int, ProviderCredential>
     */
    protected function credentials(): Collection
    {
        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            return collect();
        }

        return ProviderCredential::query()
            ->where('organization_id', $org->id)
            ->where('provider', 'forge')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  Collection<int, ProviderCredential>  $credentials
     * @return Collection<int, ForgeServer>
     */
    protected function servers(Collection $credentials): Collection
    {
        if ($credentials->isEmpty()) {
            return collect();
        }

        $query = ForgeServer::query()
            ->with(['sites' => fn ($q) => $q->orderBy('domain')])
            ->whereIn('provider_credential_id', $credentials->pluck('id'))
            ->orderBy('name');

        if (! $this->showRemoved) {
            $query->where('removed_from_source', false);
        }

        return $query->get();
    }

    /**
     * @param  Collection<int, ForgeServer>  $servers
     * @return Collection<int, ImportServerMigration>
     */
    protected function activeMigrationsForServers(Collection $servers): Collection
    {
        if ($servers->isEmpty()) {
            return collect();
        }

        $terminal = [
            ImportServerMigration::STATUS_COMPLETED,
            ImportServerMigration::STATUS_PARTIAL,
            ImportServerMigration::STATUS_ABORTED,
            ImportServerMigration::STATUS_EXPIRED,
        ];

        return ImportServerMigration::query()
            ->where('source', 'forge')
            ->whereIn('source_server_id', $servers->pluck('source_id'))
            ->whereNotIn('status', $terminal)
            ->orderByDesc('created_at')
            ->get()
            ->keyBy('source_server_id');
    }

    protected function parseTimestamp(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            return \Carbon\Carbon::parse($value);
        }

        return null;
    }
}
