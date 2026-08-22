<?php

declare(strict_types=1);

namespace App\Livewire\Cloud;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\CloudDatabase;
use App\Modules\Cloud\Jobs\AttachCloudDatabaseJob;
use App\Modules\Database\Jobs\TeardownCloudDatabaseJob;
use App\Modules\Providers\Services\DigitalOceanService;
use App\Modules\Database\Backends\DatabaseBackend;
use App\Modules\Database\Backends\DatabaseRouter;
use App\Modules\Database\Jobs\ResizeManagedDatabaseJob;
use App\Modules\Database\Services\ManagedDatabaseBackups;
use App\Modules\Database\Services\ManagedDatabaseMetrics;
use App\Modules\Database\Services\ManagedDatabaseUsers;
use App\Modules\Database\Services\TrustedSourceManager;
use App\Support\Servers\ManagedDatabaseSizeCatalog;
use Illuminate\Contracts\View\View;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Everything you do to a managed database after it exists.
 *
 * The index could create and destroy; between those two points there was
 * nothing — no way to read the connection, add a login, grow the cluster, see
 * whether it was struggling, or get back to yesterday's data. This page is that
 * middle.
 *
 * Panels are capability-gated rather than backend-gated: each asks the
 * {@see DatabaseBackend} (or the service that owns the operation) whether it
 * can do the thing, so a Neon or operator-supplied database renders an honest
 * "not available here" instead of an empty panel that reads as a failed load.
 *
 * Provider calls are made only for the visible tab. Livewire re-renders on
 * every keystroke in a bound field, and a render that unconditionally fetched
 * users + backups + four metric series would spend a DigitalOcean rate-limit
 * budget on someone typing a username.
 */
class DatabaseShow extends Component
{
    use DispatchesToastNotifications;

    public CloudDatabase $database;

    #[Url]
    public string $tab = 'overview';

    #[Url]
    public string $window = '24h';

    /** Reveals the admin password on the overview panel. Never persisted. */
    public bool $revealPassword = false;

    /** Name for the user being created. */
    public string $newUserName = '';

    /**
     * A provider-generated password, shown once immediately after a create or
     * rotate. Held in a component property and never stored — the provider will
     * not hand it back a second time, which is exactly the property that makes
     * "shown once" true rather than decorative.
     */
    public ?string $revealedSecret = null;

    public ?string $revealedSecretFor = null;

    /** Size slug selected in the scale panel. */
    public string $targetSize = '';

    /** Public IP to grant temporary access to. */
    public string $trustedIp = '';

    /** Backup timestamp + name for the restore form. */
    public string $restoreBackupAt = '';

    public string $restoreName = '';

    public bool $confirmingTearDown = false;

    public function mount(CloudDatabase $cloudDatabase): void
    {
        abort_unless(Feature::active('surface.cloud'), 404);

        $organization = auth()->user()?->currentOrganization();
        abort_if($organization === null, 403);

        // The session supplies the org, so this is the only thing between a
        // guessed ULID and another org's database. 404, not 403: whether the id
        // exists is not ours to confirm.
        abort_unless($cloudDatabase->organization_id === $organization->id, 404);

        $this->database = $cloudDatabase;
        $this->targetSize = $cloudDatabase->backendSizeSlug();
        $this->restoreName = $cloudDatabase->name.'-restore';
    }

    // -- Users ------------------------------------------------------------

    public function createUser(ManagedDatabaseUsers $users): void
    {
        $this->dismissSecret();

        try {
            $created = $users->create($this->database, $this->newUserName);
        } catch (Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->newUserName = '';
        $this->revealedSecret = $created['password'];
        $this->revealedSecretFor = $created['name'];
        $this->toastSuccess(__('User :name created.', ['name' => $created['name']]));
    }

    public function rotatePassword(ManagedDatabaseUsers $users, string $name): void
    {
        $this->dismissSecret();

        try {
            $password = $users->rotatePassword($this->database, $name);
        } catch (Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->revealedSecret = $password;
        $this->revealedSecretFor = $name;

        // Rotating the admin login invalidates the env vars every attached site
        // is holding. Say so here rather than let it surface as a 500 on their
        // next deploy.
        if ($users->isClusterAdmin($this->database, $name)) {
            $this->toastError(__('Admin password rotated. Attached apps keep the old password until you re-attach them.'));

            return;
        }

        $this->toastSuccess(__('Password rotated for :name.', ['name' => $name]));
    }

    public function deleteUser(ManagedDatabaseUsers $users, string $name): void
    {
        try {
            $users->delete($this->database, $name);
        } catch (Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->toastSuccess(__('User :name deleted.', ['name' => $name]));
    }

    public function dismissSecret(): void
    {
        $this->revealedSecret = null;
        $this->revealedSecretFor = null;
    }

    // -- Scale ------------------------------------------------------------

    public function scale(DatabaseRouter $router): void
    {
        $size = trim($this->targetSize);
        if ($size === '' || $size === $this->database->backendSizeSlug()) {
            $this->toastError(__('Choose a different plan.'));

            return;
        }

        try {
            if (! $router->backendFor($this->database)->supports(DatabaseBackend::CAP_RESIZE)) {
                $this->toastError(__('This database backend cannot resize a cluster in place.'));

                return;
            }
        } catch (Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $meta = $this->database->meta;
        $meta['resizing_to'] = $size;
        unset($meta['error'], $meta['error_at']);
        $this->database->forceFill(['meta' => $meta])->save();

        ResizeManagedDatabaseJob::dispatch((string) $this->database->id, null, $size);

        $this->toastSuccess(__('Resize queued. The cluster is unavailable while it moves — put your apps in maintenance mode if the write path matters.'));
    }

    // -- Network ----------------------------------------------------------

    public function allowIp(TrustedSourceManager $sources): void
    {
        $user = auth()->user();
        if ($user === null) {
            return;
        }

        try {
            $record = $sources->allow($this->database, $this->trustedIp, $user);
        } catch (Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->trustedIp = '';
        $this->toastSuccess(__('Access granted until :time.', [
            'time' => $record->expires_at->diffForHumans(),
        ]));
    }

    public function revokeIp(TrustedSourceManager $sources, string $recordId): void
    {
        $record = $sources->liveFor($this->database)->firstWhere('id', $recordId);
        if ($record === null) {
            $this->toastError(__('That grant is no longer active.'));

            return;
        }

        try {
            $sources->revoke($record, auth()->user());
        } catch (Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->toastSuccess(__('Access revoked.'));
    }

    // -- Sites ------------------------------------------------------------

    public function detachSite(string $siteId): void
    {
        $site = $this->database->sites()->firstWhere('sites.id', $siteId);
        if ($site === null) {
            $this->toastError(__('That app is not attached to this database.'));

            return;
        }

        AttachCloudDatabaseJob::dispatch((string) $this->database->id, (string) $site->id, detach: true);

        $this->toastSuccess(__('Detach queued for :name. Its connection variables are removed on the next deploy.', [
            'name' => $site->name,
        ]));
    }

    // -- Backups ----------------------------------------------------------

    public function restore(ManagedDatabaseBackups $backups): void
    {
        try {
            $restored = $backups->restore($this->database, $this->restoreName, $this->restoreBackupAt);
        } catch (Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->toastSuccess(__('Restoring into :name. It appears in your databases list and is not attached to anything.', [
            'name' => $restored->name,
        ]));

        $this->redirect(route('cloud.databases.show', $restored), navigate: true);
    }

    // -- Danger -----------------------------------------------------------

    public function confirmTearDown(): void
    {
        $this->confirmingTearDown = true;
    }

    public function cancelTearDown(): void
    {
        $this->confirmingTearDown = false;
    }

    public function tearDown(): void
    {
        TeardownCloudDatabaseJob::dispatch((string) $this->database->id);
        $this->database->forceFill(['status' => CloudDatabase::STATUS_DELETING])->save();

        $this->toastSuccess(__('Tear-down queued. The database cluster will be deleted on the backend shortly.'));
        $this->redirect(route('cloud.databases.index'), navigate: true);
    }

    // -- Render -----------------------------------------------------------

    public function render(
        DatabaseRouter $router,
        ManagedDatabaseUsers $users,
        ManagedDatabaseMetrics $metrics,
        ManagedDatabaseBackups $backups,
        TrustedSourceManager $sources,
    ): View {
        $this->database->loadMissing('sites');

        $capabilities = $this->capabilities($router);

        return view('livewire.cloud.database-show', [
            'capabilities' => $capabilities,
            'canManageNetwork' => $sources->supports($this->database),
            'trustedSources' => $sources->supports($this->database)
                ? $sources->liveFor($this->database)
                : collect(),
            'trustedSourceTtlHours' => $sources->ttlHours(),
            // Fetched per-tab: see the class docblock.
            'users' => $this->tab === 'users' && $capabilities[DatabaseBackend::CAP_USERS]
                ? $users->list($this->database)
                : [],
            'adminUsername' => $this->adminUsername(),
            'charts' => $this->tab === 'metrics' && $capabilities[DatabaseBackend::CAP_METRICS]
                ? $metrics->forWindow($this->database, $this->window)
                : [],
            'windows' => $metrics->windows(),
            'backups' => $this->tab === 'backups' && $capabilities[DatabaseBackend::CAP_BACKUPS]
                ? $backups->list($this->database)
                : [],
            'sizeOptions' => $this->tab === 'scale' && $capabilities[DatabaseBackend::CAP_RESIZE]
                ? $this->sizeOptions()
                : [],
            'breadcrumbs' => [
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                ['label' => __('Managed databases'), 'href' => route('cloud.databases.index'), 'icon' => 'circle-stack'],
                ['label' => $this->database->name, 'icon' => 'circle-stack'],
            ],
        ])->layout('layouts.app');
    }

    /**
     * @return array<string, bool>
     */
    private function capabilities(DatabaseRouter $router): array
    {
        $keys = [
            DatabaseBackend::CAP_USERS,
            DatabaseBackend::CAP_RESIZE,
            DatabaseBackend::CAP_METRICS,
            DatabaseBackend::CAP_BACKUPS,
        ];

        // An operator-supplied connection has no backend to ask, and a cluster
        // still provisioning has no id to ask about.
        if ($this->database->isExternal() || blank($this->database->backend_id)) {
            return array_fill_keys($keys, false);
        }

        try {
            $backend = $router->backendFor($this->database);
        } catch (Throwable) {
            return array_fill_keys($keys, false);
        }

        $capabilities = [];
        foreach ($keys as $key) {
            $capabilities[$key] = $backend->supports($key);
        }

        // Valkey exposes no user API, so the users panel would be an empty box
        // with a create form that always fails.
        if ($this->database->engine === CloudDatabase::ENGINE_REDIS) {
            $capabilities[DatabaseBackend::CAP_USERS] = false;
        }

        return $capabilities;
    }

    private function adminUsername(): string
    {
        $connection = $this->database->getAttribute('connection');
        $connection = is_array($connection) ? $connection : [];

        return trim((string) ($connection['username'] ?? $connection['user'] ?? ''));
    }

    /**
     * Plans this cluster can move to, from DigitalOcean's live catalog.
     *
     * @return list<array{value: string, label: string, group: string}>
     */
    private function sizeOptions(): array
    {
        $this->database->loadMissing('providerCredential');
        $credential = $this->database->providerCredential;
        if ($credential === null) {
            return [];
        }

        try {
            $slugs = (new DigitalOceanService($credential))
                ->getDatabaseEngineSizes($this->database->backendEngineSlug());
        } catch (Throwable) {
            // The catalog is a convenience; the current plan alone still lets
            // the panel render and say why it has nothing to offer.
            return [];
        }

        return ManagedDatabaseSizeCatalog::optionsFromSlugs($slugs);
    }
}
