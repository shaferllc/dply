<?php

declare(strict_types=1);

namespace App\Livewire\Cloud;

use App\Modules\Cloud\Jobs\TeardownCloudDatabaseJob;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\CloudDatabase;
use Illuminate\Contracts\View\View;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Org-scoped index of managed databases on the dply cloud platform.
 *
 * Mirrors {@see Index} (the cloud sites list): a single DB pull, with
 * engine / status filters computed on the collection so the tab counts
 * stay consistent across filter switches without extra round-trips.
 */
class DatabaseIndex extends Component
{
    use DispatchesToastNotifications;

    /** Filter by engine — 'all', or one of postgres / mysql / redis. */
    #[Url]
    public string $engine = 'all';

    /** Filter by status — 'all', or one of the CloudDatabase STATUS_* values. */
    #[Url]
    public string $status = 'all';

    public function mount(): void
    {
        abort_unless(Feature::active('surface.cloud'), 404);
    }

    /**
     * Queue teardown of a managed database. Org-scoped — a database
     * belonging to another org is silently ignored.
     */
    public function tearDown(string $databaseId): void
    {
        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            return;
        }

        $database = CloudDatabase::query()
            ->where('organization_id', $org->id)
            ->find($databaseId);
        if ($database === null) {
            $this->toastError(__('Database not found.'));

            return;
        }

        TeardownCloudDatabaseJob::dispatch($database->id);
        $database->forceFill(['status' => CloudDatabase::STATUS_DELETING])->save();

        $this->toastSuccess(__('Tear-down queued. The database cluster will be deleted on the backend shortly.'));
    }

    public function render(): View
    {
        $org = auth()->user()?->currentOrganization();
        abort_if($org === null, 403);

        $allDatabases = CloudDatabase::query()
            ->where('organization_id', $org->id)
            ->withCount('sites')
            ->orderByDesc('created_at')
            ->get();

        $databases = $allDatabases
            ->when(
                $this->engine !== 'all',
                fn ($c) => $c->where('engine', $this->engine),
            )
            ->when(
                $this->status !== 'all',
                fn ($c) => $c->where('status', $this->status),
            )
            ->values();

        return view('livewire.cloud.database-index', [
            'org' => $org,
            'databases' => $databases,
            'totals' => [
                'all' => $allDatabases->count(),
                'postgres' => $allDatabases->where('engine', CloudDatabase::ENGINE_POSTGRES)->count(),
                'mysql' => $allDatabases->where('engine', CloudDatabase::ENGINE_MYSQL)->count(),
                'redis' => $allDatabases->where('engine', CloudDatabase::ENGINE_REDIS)->count(),
                'provisioning' => $allDatabases->where('status', CloudDatabase::STATUS_PROVISIONING)->count(),
                'active' => $allDatabases->where('status', CloudDatabase::STATUS_ACTIVE)->count(),
                'failed' => $allDatabases->where('status', CloudDatabase::STATUS_FAILED)->count(),
            ],
        ])->layout('layouts.app');
    }
}
