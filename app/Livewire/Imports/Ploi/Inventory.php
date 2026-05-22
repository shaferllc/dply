<?php

declare(strict_types=1);

namespace App\Livewire\Imports\Ploi;

use App\Jobs\Imports\SyncPloiInventoryJob;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\ImportServerMigration;
use App\Models\PloiServer;
use App\Models\ProviderCredential;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Read-only inventory of the current organization's Ploi servers. Servers and
 * their sites land in this list via PloiInventorySync; this component just
 * renders them and exposes the "Refresh" + "Migrate this server / site"
 * actions. The migrate buttons deep-link to the server-create wizard with
 * ?from_ploi_server={id} — the wizard wiring lands in phase 2.
 *
 * Sync cadence is per Q15: lazy on mount when stale (>5 min), explicit refresh
 * button always available, snapshot-freeze handled by the migration layer (not
 * here).
 */
#[Layout('layouts.app')]
class Inventory extends Component
{
    use DispatchesToastNotifications;

    public const SYNC_STALENESS_SECONDS = 300;

    /** Toggles between hiding/showing removed_from_source rows. */
    public bool $showRemoved = false;

    public function mount(): void
    {
        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            return;
        }

        // Lazy refresh: if any of this org's Ploi credentials hasn't been synced
        // recently, fire a sync job in the background. The page renders the
        // current cached state and the user sees fresh rows on the next poll
        // (Livewire's reactivity handles the re-render once rows update).
        foreach ($this->credentials() as $credential) {
            $newest = PloiServer::query()
                ->where('provider_credential_id', $credential->id)
                ->max('last_synced_at');

            $stale = $newest === null
                || $this->parseTimestamp($newest)?->diffInSeconds(now(), absolute: true) > self::SYNC_STALENESS_SECONDS;

            if ($stale) {
                SyncPloiInventoryJob::dispatch($credential->id);
            }
        }
    }

    public function refresh(): void
    {
        $count = 0;
        foreach ($this->credentials() as $credential) {
            SyncPloiInventoryJob::dispatch($credential->id);
            $count++;
        }
        if ($count === 0) {
            $this->toastError(__('Connect a Ploi credential first.'));

            return;
        }
        $this->toastSuccess(__('Refresh queued. Inventory will update shortly.'));
    }

    public function render(): View
    {
        $credentials = $this->credentials();
        $servers = $this->servers($credentials);
        $activeMigrations = $this->activeMigrationsForServers($servers);
        $previousMigrations = $this->mostRecentTerminalMigrationsForServers($servers);

        return view('livewire.imports.ploi.inventory', [
            'credentials' => $credentials,
            'servers' => $servers,
            'hasCredentials' => $credentials->isNotEmpty(),
            'activeMigrations' => $activeMigrations,
            'activeMigrationCount' => $activeMigrations->count(),
            'previousMigrations' => $previousMigrations,
        ]);
    }

    /**
     * Most-recent terminal migration per source_server_id, surfaced as a
     * "View last migration" link on the inventory page so the user has
     * a way back into history after a completed/aborted run.
     *
     * @param  Collection<int, PloiServer>  $servers
     * @return Collection<int, ImportServerMigration>
     */
    protected function mostRecentTerminalMigrationsForServers($servers): Collection
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
            ->where('source', 'ploi')
            ->whereIn('source_server_id', $servers->pluck('source_id'))
            ->whereIn('status', $terminal)
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('source_server_id')
            ->map(fn ($group) => $group->first());
    }

    /**
     * Map of source_server_id (int) → ImportServerMigration for any of these
     * PloiServers that has an active migration (not in a terminal state).
     * Used by the Blade to show a "View migration" badge in place of the
     * "Migrate" CTA — enforces the per-PloiServer lock from Q18 visibly.
     *
     * @param  Collection<int, PloiServer>  $servers
     * @return Collection<int, ImportServerMigration>
     */
    protected function activeMigrationsForServers($servers): Collection
    {
        if ($servers->isEmpty()) {
            return collect();
        }

        $terminalStatuses = [
            ImportServerMigration::STATUS_COMPLETED,
            ImportServerMigration::STATUS_PARTIAL,
            ImportServerMigration::STATUS_ABORTED,
        ];

        return ImportServerMigration::query()
            ->where('source', 'ploi')
            ->whereIn('source_server_id', $servers->pluck('source_id'))
            ->whereNotIn('status', $terminalStatuses)
            ->orderByDesc('created_at')
            ->get()
            ->keyBy('source_server_id');
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
            ->where('provider', 'ploi')
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  Collection<int, ProviderCredential>  $credentials
     * @return Collection<int, PloiServer>
     */
    protected function servers(Collection $credentials): Collection
    {
        if ($credentials->isEmpty()) {
            return collect();
        }

        $query = PloiServer::query()
            ->with(['sites' => fn ($q) => $q->orderBy('domain')])
            ->whereIn('provider_credential_id', $credentials->pluck('id'))
            ->orderBy('name');

        if (! $this->showRemoved) {
            $query->where('removed_from_source', false);
        }

        return $query->get();
    }

    protected function parseTimestamp(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            return Carbon::parse($value);
        }

        return null;
    }
}
