<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Models\ServerDatabase;
use App\Models\ServerDatabaseAuditEvent;
use App\Services\Servers\ServerDatabaseAuditLogger;
use App\Services\Servers\ServerDatabaseInventory;
use App\Support\Servers\DatabaseWorkspaceEngines;
use Illuminate\Support\Str;

/**
 * "What is actually on this box, versus what dply thinks is on it."
 *
 * Surfaces two kinds of drift on the server Databases workspace:
 *   - UNTRACKED — a real database dply has no record of. Invisible everywhere
 *     in the product until adopted, which is the gap this closes.
 *   - MISSING — a tracked row whose database is gone. Still listed in pickers,
 *     still offers backups that will fail.
 *
 * The scan is SSH-bound so it runs wire:init-deferred, never on first render
 * (CLAUDE.md), and caches to server.meta so read-only surfaces can render the
 * result without touching the wire.
 */
trait ManagesDatabaseInventory
{
    /** @var list<array{engine: string, name: string}> */
    public array $untrackedDatabases = [];

    /** @var list<array{id: string, engine: string, name: string}> */
    public array $missingDatabases = [];

    public ?string $inventoryScannedAt = null;

    public bool $inventoryLoaded = false;

    /** Non-empty when the last scan could not reach one or more engines. */
    public string $inventoryWarning = '';

    /** Deferred entry point (wire:init) — safe to call repeatedly. */
    public function loadDatabaseInventory(ServerDatabaseInventory $inventory): void
    {
        $this->authorize('view', $this->server);

        if ($this->inventoryLoaded) {
            return;
        }

        try {
            $inventory->scan($this->server);
        } catch (\Throwable $e) {
            // A scan failure must not break the page — the rest of the
            // workspace works without it.
            $this->inventoryWarning = __('Could not scan this server for databases: :error', [
                'error' => Str::limit($e->getMessage(), 160),
            ]);
        }

        $this->inventoryLoaded = true;
        $this->refreshInventoryLists($inventory);
    }

    /** Explicit re-scan from the UI. */
    public function rescanDatabaseInventory(ServerDatabaseInventory $inventory): void
    {
        $this->authorize('update', $this->server);

        $this->inventoryLoaded = false;
        $this->inventoryWarning = '';
        $this->loadDatabaseInventory($inventory);

        $count = count($this->untrackedDatabases);
        $this->toastSuccess($count === 0
            ? __('Scanned this server — every database on it is already tracked.')
            : trans_choice('{1} Scanned this server — found :count untracked database.|[2,*] Scanned this server — found :count untracked databases.', $count, ['count' => $count]));
    }

    /**
     * Record an existing database found on the server.
     *
     * Credentials are NOT known (dply did not create it), so the row is created
     * gated: backups and other admin-path work fine, .env wiring and the
     * credential link stay off until a password is supplied or rotated.
     */
    public function adoptUntrackedDatabase(string $engine, string $name, ServerDatabaseInventory $inventory, ServerDatabaseAuditLogger $audit): void
    {
        $this->authorize('update', $this->server);

        // Only adopt something the last scan actually saw — never trust an
        // engine/name pair posted back from the browser.
        $seen = collect($inventory->untracked($this->server))
            ->contains(fn (array $row): bool => $row['engine'] === $engine && $row['name'] === $name);

        if (! $seen) {
            $this->toastError(__('That database is no longer listed on this server — rescan and try again.'));

            return;
        }

        try {
            $db = $inventory->adopt($this->server, $engine, $name);
        } catch (\Throwable $e) {
            $this->toastError(Str::limit($e->getMessage(), 200));

            return;
        }

        $audit->record($this->server, ServerDatabaseAuditEvent::EVENT_DATABASE_ADOPTED, [
            'server_database_id' => $db->id,
            'engine' => $db->engine,
            'name' => $db->name,
            'source' => 'server_inventory_scan',
        ], auth()->user());

        $this->refreshInventoryLists($inventory);
        $this->toastSuccess(__('Now tracking :name. dply does not hold its password — rotate it to enable environment wiring.', [
            'name' => $db->name,
        ]));
    }

    /**
     * Delete a tracked row whose database is gone from the server.
     *
     * Always operator-initiated: the row is the only record of the database's
     * credentials and site link, so a scan must never remove it on its own.
     */
    public function forgetMissingDatabase(string $id, ServerDatabaseInventory $inventory): void
    {
        $this->authorize('update', $this->server);

        $stillMissing = collect($inventory->missing($this->server))
            ->contains(fn (ServerDatabase $db): bool => (string) $db->id === $id);

        if (! $stillMissing) {
            $this->toastError(__('That database is back on the server — rescan and try again.'));

            return;
        }

        $db = ServerDatabase::query()->where('server_id', $this->server->id)->find($id);
        if ($db === null) {
            return;
        }

        $name = (string) $db->name;
        $db->delete();

        $this->refreshInventoryLists($inventory);
        $this->toastSuccess(__('Removed the record for :name. Nothing was changed on the server.', ['name' => $name]));
    }

    private function refreshInventoryLists(ServerDatabaseInventory $inventory): void
    {
        $this->server->refresh();

        $this->untrackedDatabases = $inventory->untracked($this->server);
        $this->missingDatabases = array_map(
            static fn (ServerDatabase $db): array => [
                'id' => (string) $db->id,
                'engine' => DatabaseWorkspaceEngines::label((string) $db->engine),
                'name' => (string) $db->name,
            ],
            $inventory->missing($this->server),
        );
        $this->inventoryScannedAt = (string) (data_get($this->server->meta, ServerDatabaseInventory::META_KEY.'.scanned_at') ?: '') ?: null;
    }
}
