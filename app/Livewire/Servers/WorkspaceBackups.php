<?php

namespace App\Livewire\Servers;

use App\Jobs\ExportServerDatabaseBackupJob;
use App\Jobs\ExportSiteFileBackupJob;
use App\Livewire\Concerns\AuthorsBackupDestinations;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\BackupConfiguration;
use App\Models\ObjectStorageCredential;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\ServerBackupSchedule;
use App\Models\ServerCronJob;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseBackup;
use App\Models\Site;
use App\Models\SiteFileBackup;
use App\Notifications\BackupFailureNotification;
use App\Services\DigitalOceanService;
use App\Services\Servers\DatabaseBackupDownloader;
use App\Services\Servers\DatabaseBackupExporter;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Services\Storage\ObjectStorageBucketProvisioner;
use App\Support\Servers\DatabaseBackupSettings;
use Cron\CronExpression;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Component;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use Livewire\Attributes\Lazy;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Backups workspace at {@see servers.backups} (server-wide) and {@see sites.backups}
 * (site-scoped). Surfaces {@see ServerDatabaseBackup} and {@see SiteFileBackup}
 * runs plus recurring schedule CRUD via {@see ServerBackupSchedule}.
 *
 * Schedules materialize as {@see ServerCronJob} entries that invoke
 * `dply:run-backup-schedule {schedule}` so the cron line stays stable across
 * cadence edits.
 */
#[Layout('layouts.app')]
#[Lazy]
class WorkspaceBackups extends Component
{
    use RendersWorkspacePlaceholder;
    use AuthorsBackupDestinations;
    use ConfirmsActionWithModal;
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;
    use RequiresFeature;

    protected string $requiredFeature = 'workspace.backups';

    /** When true, render the coming-soon teaser instead of the full workspace. */
    public bool $comingSoonPreview = false;

    /** Form state for "Run database backup now". */
    public string $run_database_id = '';

    /** Optional one-off S3 destination override (empty = server default). */
    public string $run_database_backup_configuration_id = '';

    /** Server default: remote_server or destination. */
    public string $db_backup_default_kind = DatabaseBackupSettings::KIND_REMOTE_SERVER;

    public string $db_backup_configuration_id = '';

    /** Display field (GB); persisted as bytes in server meta. */
    public string $db_backup_remote_max_gb = '';

    /** Form state for "Run site files backup now". */
    public string $run_site_id = '';

    /** New schedule form. */
    public string $new_target_type = ServerBackupSchedule::TARGET_DATABASE;

    public string $new_target_id = '';

    public string $new_cron_expression = '0 3 * * *';

    public ?string $new_backup_configuration_id = null;

    /**
     * "Add backup destination" modal state. Mirrors the settings page form but
     * scoped to the current server's organization, so a teammate adding an
     * S3 bucket here for server A immediately makes it available on every
     * other server in the org too.
     */
    public bool $showDestinationModal = false;

    /** @var array<string, mixed> */
    public array $destinationForm = [];

    /**
     * Add-destination modal mode:
     *   'connect'  — paste credentials for an existing bucket (any provider).
     *   'provision' — create a brand-new bucket on a provider (DO Spaces /
     *                 Hetzner) via ObjectStorageBucketProvisioner, then wire it.
     */
    public string $destination_create_mode = 'connect';

    /** @var array<string, string> Form for the 'provision' mode. */
    public array $provisionForm = [
        'name' => '',
        'provider' => 'digitalocean_spaces',
        'region' => '',
        'bucket' => '',
        'access_key' => '',
        'secret' => '',
    ];

    /** Reuse a saved ObjectStorageCredential instead of entering keys (manual-key providers). */
    public string $provision_credential_id = '';

    /** Save the entered keys as a reusable ObjectStorageCredential (manual-key providers). */
    public bool $provision_save_credential = true;

    /** When set (site route or ?site=), all queries narrow to that site. */
    public ?string $context_site_id = null;

    /** True when mounted from {@see sites.backups} (native site workspace, not a server filter). */
    public bool $siteDedicatedContext = false;

    /** overview | schedules | history */
    public string $backups_workspace_tab = 'overview';

    public function setBackupsWorkspaceTab(string $tab): void
    {
        $this->backups_workspace_tab = in_array($tab, ['overview', 'schedules', 'history'], true) ? $tab : 'overview';
    }

    public function bootedRequiresFeature(): void
    {
        if ($this->comingSoonPreview) {
            return;
        }

        $flag = $this->requiredFeature ?? '';
        if ($flag !== '' && ! Feature::active($flag)) {
            abort(404);
        }
    }

    public function mount(Server $server, ?Site $site = null): void
    {
        if ($site !== null) {
            abort_unless($site->server_id === $server->id, 404);
            abort_unless($server->organization_id === auth()->user()->currentOrganization()?->id, 404);
            Gate::authorize('view', $site);

            $this->siteDedicatedContext = true;
            $this->context_site_id = $site->id;
            $this->new_target_type = ServerBackupSchedule::TARGET_SITE_FILES;
            $this->new_target_id = $site->id;
        }

        if (! Feature::active('workspace.backups')) {
            if (workspace_backups_preview_active()) {
                $this->comingSoonPreview = true;
                $this->bootWorkspace($server);

                return;
            }

            abort(404);
        }

        $this->bootWorkspace($server);
        $this->destinationForm = $this->emptyDestinationForm();
        $this->hydrateDatabaseBackupSettings();

        if ($this->context_site_id !== null) {
            return;
        }

        // Server workspace can deep-link with ?site= to pre-filter without leaving server nav.
        $siteId = request()->query('site');
        if (is_string($siteId) && $siteId !== '') {
            $exists = Site::query()
                ->where('server_id', $server->id)
                ->whereKey($siteId)
                ->exists();
            if ($exists) {
                $this->context_site_id = $siteId;
                $this->new_target_type = ServerBackupSchedule::TARGET_SITE_FILES;
                $this->new_target_id = $siteId;
            }
        }
    }

    /**
     * Opens the inline "Add backup destination" modal. The created destination
     * belongs to the server's organization and is immediately selected on the
     * schedule form, so the operator stays on the same page from "no
     * destinations" to "schedule queued".
     */
    public function openDestinationModal(): void
    {
        $this->authorize('create', BackupConfiguration::class);
        $this->resetErrorBag();
        $this->destinationForm = $this->emptyDestinationForm();
        $this->destination_create_mode = 'connect';
        $this->resetProvisionForm();
        $this->showDestinationModal = true;
    }

    public function closeDestinationModal(): void
    {
        $this->showDestinationModal = false;
        $this->destinationForm = $this->emptyDestinationForm();
        $this->destination_create_mode = 'connect';
        $this->resetProvisionForm();
        $this->resetErrorBag();
    }

    protected function resetProvisionForm(): void
    {
        $this->provisionForm = [
            'name' => '',
            'provider' => 'digitalocean_spaces',
            'region' => '',
            'bucket' => '',
            'access_key' => '',
            'secret' => '',
        ];
        $this->provision_credential_id = '';
        $this->provision_save_credential = true;
    }

    /**
     * Can dply mint object-storage keys for the selected provider from a
     * connected cloud API token? True for api_managed providers (DigitalOcean
     * Spaces) when the org has a matching ProviderCredential — in that case the
     * operator never pastes keys.
     */
    public function provisionCanAutoMint(): bool
    {
        return $this->autoMintProviderCredential($this->provisionForm['provider'] ?? '') !== null;
    }

    protected function autoMintProviderCredential(string $provider): ?ProviderCredential
    {
        $meta = (array) config('object_storage.providers.'.$provider, []);
        $apiProvider = (string) ($meta['api_provider'] ?? '');
        if (! (bool) ($meta['api_managed'] ?? false) || $apiProvider === '' || $this->server->organization_id === null) {
            return null;
        }

        return ProviderCredential::query()
            ->where('organization_id', $this->server->organization_id)
            ->where('provider', $apiProvider)
            ->orderBy('created_at')
            ->first();
    }

    /**
     * Saved object-storage credentials for the selected provider, offered as a
     * "reuse keys" picker for manual-key providers (e.g. Hetzner).
     *
     * @return \Illuminate\Support\Collection<int, ObjectStorageCredential>
     */
    public function savedObjectStorageCredentials(): \Illuminate\Support\Collection
    {
        if ($this->server->organization_id === null) {
            return collect();
        }

        return ObjectStorageCredential::query()
            ->where('organization_id', $this->server->organization_id)
            ->where('provider', $this->provisionForm['provider'] ?? '')
            ->orderBy('name')
            ->get();
    }

    /**
     * Providers we can create a bucket on inline. Sourced from
     * config/object_storage.php (provision-capable only) so the picker and the
     * provisioner agree on what's possible.
     *
     * @return array<string, array{label: string, regions: array<string, string>}>
     */
    public function provisionableObjectStorageProviders(): array
    {
        $out = [];
        foreach ((array) config('object_storage.providers', []) as $key => $meta) {
            if (! is_array($meta) || ! (bool) ($meta['provision'] ?? false)) {
                continue;
            }
            $out[$key] = [
                'label' => (string) ($meta['label'] ?? $key),
                'regions' => (array) ($meta['regions'] ?? []),
                'key_help' => (string) ($meta['key_help'] ?? ''),
                'key_console_url' => (string) ($meta['key_console_url'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Create a brand-new bucket on the chosen provider and wire it up as a
     * backup destination — the "create an S3 from here" path. Uses the operator
     * S3 keys entered in the form; the bucket is created via a single
     * CreateBucket call, then persisted as a BackupConfiguration.
     */
    public function provisionDestinationBucket(ObjectStorageBucketProvisioner $provisioner): void
    {
        $this->authorize('create', BackupConfiguration::class);

        $org = $this->server->organization;
        if ($org === null) {
            $this->toastError(__('This server has no organization — refresh the page.'));

            return;
        }

        $this->resetErrorBag();
        $providers = $this->provisionableObjectStorageProviders();
        $provider = $this->provisionForm['provider'];

        $providerCredential = $this->autoMintProviderCredential($provider);
        $reuseId = trim($this->provision_credential_id);
        // Keys are needed manually only when we can't mint them AND the operator
        // isn't reusing a saved credential.
        $needsManualKeys = $providerCredential === null && $reuseId === '';

        $rules = [
            'provisionForm.name' => ['required', 'string', 'max:160'],
            'provisionForm.provider' => ['required', 'string', Rule::in(array_keys($providers))],
            'provisionForm.region' => ['required', 'string', 'max:100'],
            'provisionForm.bucket' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9][a-z0-9.\-]{1,61}[a-z0-9]$/'],
        ];
        if ($needsManualKeys) {
            $rules['provisionForm.access_key'] = ['required', 'string', 'max:500'];
            $rules['provisionForm.secret'] = ['required', 'string', 'max:4000'];
        }
        $this->validate($rules, [], ['provisionForm.bucket' => __('bucket name')]);

        $region = trim($this->provisionForm['region']);
        $bucket = trim($this->provisionForm['bucket']);

        // Resolve the S3 keys: mint from the cloud token, reuse a saved
        // credential, or use the keys typed into the form.
        $savedCredential = null;
        if ($providerCredential instanceof ProviderCredential) {
            try {
                // api_managed providers are DigitalOcean-only today (see object_storage.php).
                $minted = (new DigitalOceanService($providerCredential))->createSpacesKey('dply-'.$bucket, []);
                $accessKey = (string) $minted['access_key'];
                $secret = (string) $minted['secret_key'];
            } catch (\Throwable $e) {
                $this->addError('provisionForm.bucket', __('Could not create storage keys from your connected token: :err', ['err' => $e->getMessage()]));

                return;
            }
        } elseif ($reuseId !== '') {
            $savedCredential = ObjectStorageCredential::query()
                ->where('organization_id', $org->id)
                ->where('provider', $provider)
                ->whereKey($reuseId)
                ->first();
            if (! $savedCredential instanceof ObjectStorageCredential) {
                $this->addError('provision_credential_id', __('That saved storage credential is no longer available.'));

                return;
            }
            $accessKey = (string) $savedCredential->access_key_id;
            $secret = (string) $savedCredential->secret_access_key;
        } else {
            $accessKey = trim($this->provisionForm['access_key']);
            $secret = $this->provisionForm['secret'];
        }

        // When we just minted the key from the cloud token, give the S3 gateway
        // a moment to activate it (DO Spaces keys aren't usable instantly).
        $freshlyMinted = $providerCredential instanceof ProviderCredential;
        try {
            $result = $provisioner->create($provider, $region, $accessKey, $secret, $bucket, awaitKeyPropagation: $freshlyMinted);
        } catch (\Throwable $e) {
            $this->addError('provisionForm.bucket', $e->getMessage());

            return;
        }

        // Persist manually-entered keys for reuse when asked (minted/reused keys
        // are already managed or saved).
        if ($needsManualKeys && $this->provision_save_credential) {
            ObjectStorageCredential::query()->create([
                'organization_id' => $org->id,
                'created_by_user_id' => Auth::id(),
                'provider' => $provider,
                'name' => ($providers[$provider]['label'] ?? $provider).' '.__('keys'),
                'access_key_id' => $accessKey,
                'secret_access_key' => $secret,
                'region' => $region !== '' ? $region : null,
                'endpoint' => $result['endpoint'] !== '' ? $result['endpoint'] : null,
            ]);
        }

        // Map the object-storage provider onto a BackupConfiguration provider the
        // database exporter's S3 client factory understands. DO Spaces has its
        // own entry; everything else (e.g. Hetzner) rides Custom S3 with an
        // explicit endpoint + path-style addressing.
        $backupProvider = $provider === 'digitalocean_spaces'
            ? BackupConfiguration::PROVIDER_DIGITALOCEAN_SPACES
            : BackupConfiguration::PROVIDER_CUSTOM_S3;

        $row = $org->backupConfigurations()->create([
            'name' => $this->provisionForm['name'],
            'provider' => $backupProvider,
            'config' => [
                'access_key' => $accessKey,
                'secret' => $secret,
                'bucket' => $bucket,
                'region' => $region,
                'endpoint' => $result['endpoint'],
                'use_path_style' => $provider !== 'digitalocean_spaces',
            ],
            'created_by_user_id' => Auth::id(),
        ]);

        $this->new_backup_configuration_id = $row->id;
        $this->db_backup_configuration_id = $row->id;

        $this->showDestinationModal = false;
        $this->destinationForm = $this->emptyDestinationForm();
        $this->destination_create_mode = 'connect';
        $this->resetProvisionForm();
        $this->toastSuccess(__('Created bucket :bucket and added it as a backup destination.', ['bucket' => $bucket]));
    }

    public function saveDestination(): void
    {
        $this->authorize('create', BackupConfiguration::class);

        $org = $this->server->organization;
        if ($org === null) {
            $this->toastError(__('This server has no organization — refresh the page.'));

            return;
        }

        $this->resetErrorBag();
        $this->validate($this->destinationFormRules('destinationForm', $this->destinationForm['provider'] ?? ''));
        $this->validateDestinationFormExtras('destinationForm', $this->destinationForm);

        $row = $org->backupConfigurations()->create([
            'name' => $this->destinationForm['name'],
            'provider' => $this->destinationForm['provider'],
            'config' => $this->extractDestinationConfig($this->destinationForm),
            'created_by_user_id' => Auth::id(),
        ]);

        // Auto-select the new destination so the operator's next action is to
        // submit the schedule, not to scroll-find the entry they just created.
        $this->new_backup_configuration_id = $row->id;
        $this->db_backup_configuration_id = $row->id;

        $this->showDestinationModal = false;
        $this->destinationForm = $this->emptyDestinationForm();
        $this->destination_create_mode = 'connect';
        $this->resetProvisionForm();
        $this->toastSuccess(__('Backup destination added — selected on this schedule.'));
    }

    public function saveDatabaseBackupSettings(): void
    {
        $this->authorize('update', $this->server);

        $this->validate([
            'db_backup_default_kind' => 'required|in:remote_server,destination',
            'db_backup_configuration_id' => 'nullable|string',
            'db_backup_remote_max_gb' => 'nullable|numeric|min:0.1|max:10000',
        ]);

        if ($this->db_backup_default_kind === DatabaseBackupSettings::KIND_DESTINATION && $this->db_backup_configuration_id === '') {
            $this->addError('db_backup_configuration_id', __('Pick a backup destination when using S3 storage.'));

            return;
        }

        $maxBytes = null;
        if ($this->db_backup_remote_max_gb !== '') {
            $maxBytes = (int) round((float) $this->db_backup_remote_max_gb * 1024 * 1024 * 1024);
        }

        $settings = new DatabaseBackupSettings(
            defaultKind: $this->db_backup_default_kind,
            backupConfigurationId: $this->db_backup_configuration_id !== '' ? $this->db_backup_configuration_id : null,
            remoteMaxBytes: $maxBytes,
        );

        $meta = $this->server->meta ?? [];
        $meta['database_backup'] = $settings->toMetaArray();
        $this->server->update(['meta' => $meta]);
        $this->server->refresh();

        $this->toastSuccess(__('Database backup storage settings saved.'));
    }

    protected function hydrateDatabaseBackupSettings(): void
    {
        $settings = DatabaseBackupSettings::fromServer($this->server);
        $this->db_backup_default_kind = $settings->defaultKind;
        $this->db_backup_configuration_id = $settings->backupConfigurationId ?? '';
        $bytes = $settings->remoteMaxBytes ?? (int) config('server_database.remote_backup_max_bytes_per_server', 10 * 1024 * 1024 * 1024);
        $this->db_backup_remote_max_gb = (string) round($bytes / 1024 / 1024 / 1024, 1);
    }

    public function runDatabaseBackup(): void
    {
        $this->authorize('update', $this->server);

        $database = ServerDatabase::query()
            ->where('server_id', $this->server->id)
            ->whereKey($this->run_database_id)
            ->first();

        if ($database === null) {
            $this->toastError(__('Pick a database to back up.'));

            return;
        }

        $backup = ServerDatabaseBackup::create([
            'server_database_id' => $database->id,
            'user_id' => auth()->id(),
            'status' => ServerDatabaseBackup::STATUS_PENDING,
        ]);

        app(DatabaseBackupExporter::class)->prepareBackupRow(
            $backup,
            $this->server,
            $this->run_database_backup_configuration_id !== '' ? $this->run_database_backup_configuration_id : null,
        );

        ExportServerDatabaseBackupJob::dispatch($backup->id);

        if ($this->server->organization) {
            audit_log($this->server->organization, auth()->user(), 'backup.database.run_dispatched', $this->server, null, [
                'backup_id' => (string) $backup->id,
                'database_id' => (string) $database->id,
                'database_name' => $database->name,
            ]);
        }

        $this->run_database_id = '';
        $this->toastSuccess(__('Database backup queued for :name.', ['name' => $database->name]));
    }

    public function runSiteFilesBackup(): void
    {
        $this->authorize('update', $this->server);

        $site = Site::query()
            ->where('server_id', $this->server->id)
            ->whereKey($this->run_site_id)
            ->first();

        if ($site === null) {
            $this->toastError(__('Pick a site to back up.'));

            return;
        }

        $backup = SiteFileBackup::create([
            'site_id' => $site->id,
            'user_id' => auth()->id(),
            'status' => SiteFileBackup::STATUS_PENDING,
        ]);

        ExportSiteFileBackupJob::dispatch($backup->id);

        if ($this->server->organization) {
            audit_log($this->server->organization, auth()->user(), 'backup.site_files.run_dispatched', $site, null, [
                'backup_id' => (string) $backup->id,
                'site_id' => (string) $site->id,
                'site_name' => $site->name,
            ]);
        }

        $this->run_site_id = '';
        $this->toastSuccess(__('Site files backup queued for :name.', ['name' => $site->name]));
    }

    public function addSchedule(): void
    {
        $this->authorize('update', $this->server);

        $this->validate([
            'new_target_type' => 'required|in:database,site_files',
            'new_target_id' => 'required|string',
            'new_cron_expression' => 'required|string|max:64',
            'new_backup_configuration_id' => 'nullable|string',
        ]);

        $exists = match ($this->new_target_type) {
            ServerBackupSchedule::TARGET_DATABASE => ServerDatabase::query()
                ->where('server_id', $this->server->id)
                ->whereKey($this->new_target_id)
                ->exists(),
            ServerBackupSchedule::TARGET_SITE_FILES => Site::query()
                ->where('server_id', $this->server->id)
                ->whereKey($this->new_target_id)
                ->exists(),
            default => false,
        };
        if (! $exists) {
            $this->toastError(__('Target not found on this server.'));

            return;
        }

        $schedule = ServerBackupSchedule::create([
            'server_id' => $this->server->id,
            'target_type' => $this->new_target_type,
            'target_id' => $this->new_target_id,
            'backup_configuration_id' => $this->new_backup_configuration_id ?: null,
            'cron_expression' => $this->new_cron_expression,
            'is_active' => true,
        ]);

        // The cron entry runs the dply control-plane artisan command (this dply install),
        // not anything on the remote server — so user defaults to root and host is irrelevant
        // for execution. We just need a stable record so the schedule can be edited/disabled.
        $cronJob = ServerCronJob::create([
            'server_id' => $this->server->id,
            'cron_expression' => $this->new_cron_expression,
            'command' => 'php '.base_path('artisan').' dply:run-backup-schedule '.$schedule->id,
            'user' => 'root',
            'enabled' => true,
            'description' => 'Backup schedule '.$schedule->id,
            'system_managed' => true,
        ]);

        $schedule->update(['server_cron_job_id' => $cronJob->id]);

        if ($org = $this->server->organization) {
            audit_log($org, auth()->user(), 'backup.schedule.created', $schedule, null, [
                'target_type' => $schedule->target_type,
                'target_id' => $schedule->target_id,
                'cron_expression' => $schedule->cron_expression,
            ]);
        }

        $this->reset(['new_target_id', 'new_backup_configuration_id']);
        $this->new_cron_expression = '0 3 * * *';
        $this->toastSuccess(__('Backup schedule added.'));
    }

    public function downloadDatabaseBackup(string $backupId, DatabaseBackupDownloader $downloader): StreamedResponse|Response|null
    {
        $this->authorize('update', $this->server);
        $backup = ServerDatabaseBackup::query()
            ->whereKey($backupId)
            ->whereHas('serverDatabase', fn ($q) => $q->where('server_id', $this->server->id))
            ->with('serverDatabase')
            ->firstOrFail();

        $extension = $backup->serverDatabase?->engine === 'sqlite' ? 'db' : 'sql';
        $filename = ($backup->serverDatabase?->name ?? 'database').'-'.$backup->id.'.'.$extension;

        try {
            return $downloader->response($backup, $filename);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return null;
        }
    }

    public function downloadFileBackup(string $backupId): StreamedResponse|Response|null
    {
        $this->authorize('update', $this->server);
        $backup = SiteFileBackup::query()
            ->whereKey($backupId)
            ->whereHas('site', fn ($q) => $q->where('server_id', $this->server->id))
            ->with('site')
            ->firstOrFail();

        if ($backup->status !== SiteFileBackup::STATUS_COMPLETED || empty($backup->disk_path)) {
            $this->toastError(__('Backup is not ready yet.'));

            return null;
        }

        if (! Storage::disk('local')->exists($backup->disk_path)) {
            $this->toastError(__('Backup file is missing from storage.'));

            return null;
        }

        $slug = $backup->site?->slug;
        $name = 'site-files-'.(($slug !== null && $slug !== '') ? $slug : 'site').'-'.$backup->id.'.tar.gz';

        return Storage::disk('local')->download($backup->disk_path, $name);
    }

    public function deleteSchedule(string $scheduleId): void
    {
        $this->authorize('update', $this->server);

        $schedule = ServerBackupSchedule::query()
            ->where('server_id', $this->server->id)
            ->whereKey($scheduleId)
            ->first();
        if ($schedule === null) {
            return;
        }

        if ($schedule->server_cron_job_id) {
            ServerCronJob::query()->whereKey($schedule->server_cron_job_id)->delete();
        }

        if ($org = $this->server->organization) {
            audit_log($org, auth()->user(), 'backup.schedule.deleted', $schedule, [
                'cron_expression' => $schedule->cron_expression,
            ], null);
        }

        $schedule->delete();
        $this->toastSuccess(__('Backup schedule removed.'));
    }

    /** Inline-edit form state: schedule id → new cron expression. Empty = not editing. */
    public array $editing_schedules = [];

    public function startEditSchedule(string $scheduleId): void
    {
        $schedule = ServerBackupSchedule::query()
            ->where('server_id', $this->server->id)
            ->whereKey($scheduleId)
            ->first();
        if ($schedule === null) {
            return;
        }
        $this->editing_schedules[$scheduleId] = $schedule->cron_expression;
    }

    public function cancelEditSchedule(string $scheduleId): void
    {
        unset($this->editing_schedules[$scheduleId]);
    }

    public function saveScheduleCadence(string $scheduleId): void
    {
        $this->authorize('update', $this->server);

        $newCron = trim((string) ($this->editing_schedules[$scheduleId] ?? ''));
        if ($newCron === '' || strlen($newCron) > 64) {
            $this->toastError(__('Invalid cron expression.'));

            return;
        }

        $schedule = ServerBackupSchedule::query()
            ->where('server_id', $this->server->id)
            ->whereKey($scheduleId)
            ->first();
        if ($schedule === null) {
            return;
        }

        $oldCron = $schedule->cron_expression;
        $schedule->update(['cron_expression' => $newCron]);
        if ($schedule->server_cron_job_id) {
            ServerCronJob::query()
                ->whereKey($schedule->server_cron_job_id)
                ->update(['cron_expression' => $newCron]);
        }

        if ($org = $this->server->organization) {
            audit_log(
                $org,
                auth()->user(),
                'backup.schedule.cadence_updated',
                $schedule,
                ['cron_expression' => $oldCron],
                ['cron_expression' => $newCron],
            );
        }

        unset($this->editing_schedules[$scheduleId]);
        $this->toastSuccess(__('Schedule updated.'));
    }

    /**
     * Kick off the export job for a schedule's target immediately — same path the
     * cron tick takes. Useful for testing a freshly-added schedule or after fixing
     * destination credentials.
     */
    /**
     * Fire a one-shot {@see BackupFailureNotification} with the
     * test marker so operators can validate their email/recipient setup without
     * inducing an actual backup failure.
     */
    public function sendTestAlert(string $scheduleId): void
    {
        $this->authorize('update', $this->server);

        $schedule = ServerBackupSchedule::query()
            ->where('server_id', $this->server->id)
            ->whereKey($scheduleId)
            ->first();
        if ($schedule === null) {
            return;
        }

        $org = $this->server->organization;
        if ($org === null) {
            $this->toastError(__('No organization for this server.'));

            return;
        }

        $admins = $org->users()->wherePivotIn('role', ['owner', 'admin'])->get();
        if ($admins->isEmpty()) {
            $this->toastError(__('No org admins to send to.'));

            return;
        }

        Notification::send($admins, new BackupFailureNotification(
            schedule: $schedule,
            errorMessage: __('Test alert triggered by :user.', ['user' => auth()->user()?->email ?? 'operator']),
            serverName: (string) ($this->server->name ?? ''),
            isTest: true,
        ));

        audit_log($org, auth()->user(), 'backup.schedule.test_alert', $schedule);

        $this->toastSuccess(__('Test alert sent to :n admin(s).', ['n' => $admins->count()]));
    }

    public function toggleNotifyOnFailure(string $scheduleId): void
    {
        $this->authorize('update', $this->server);

        $schedule = ServerBackupSchedule::query()
            ->where('server_id', $this->server->id)
            ->whereKey($scheduleId)
            ->first();
        if ($schedule === null) {
            return;
        }

        $newValue = ! $schedule->notify_on_failure;
        $schedule->update(['notify_on_failure' => $newValue]);

        if ($org = $this->server->organization) {
            audit_log(
                $org,
                auth()->user(),
                $newValue ? 'backup.schedule.notify_enabled' : 'backup.schedule.notify_disabled',
                $schedule,
            );
        }

        $this->toastSuccess($newValue ? __('Failure alerts enabled.') : __('Failure alerts disabled.'));
    }

    public function runScheduleNow(string $scheduleId): void
    {
        $this->authorize('update', $this->server);

        $schedule = ServerBackupSchedule::query()
            ->where('server_id', $this->server->id)
            ->whereKey($scheduleId)
            ->first();
        if ($schedule === null) {
            return;
        }

        match ($schedule->target_type) {
            ServerBackupSchedule::TARGET_DATABASE => $this->dispatchScheduleDatabase($schedule),
            ServerBackupSchedule::TARGET_SITE_FILES => $this->dispatchScheduleSiteFiles($schedule),
            default => $this->toastError(__('Unknown target type.')),
        };

        if ($org = $this->server->organization) {
            audit_log($org, auth()->user(), 'backup.schedule.run_now', $schedule);
        }
    }

    private function dispatchScheduleDatabase(ServerBackupSchedule $schedule): void
    {
        $database = ServerDatabase::query()
            ->where('server_id', $this->server->id)
            ->whereKey($schedule->target_id)
            ->first();
        if ($database === null) {
            $this->toastError(__('Schedule target database is missing.'));

            return;
        }

        $backup = ServerDatabaseBackup::create([
            'server_database_id' => $database->id,
            'user_id' => auth()->id(),
            'status' => ServerDatabaseBackup::STATUS_PENDING,
        ]);

        app(DatabaseBackupExporter::class)->prepareBackupRow(
            $backup,
            $this->server,
            $schedule->backup_configuration_id,
        );

        ExportServerDatabaseBackupJob::dispatch($backup->id);
        $this->toastSuccess(__('Backup queued for :name.', ['name' => $database->name]));
    }

    private function dispatchScheduleSiteFiles(ServerBackupSchedule $schedule): void
    {
        $site = Site::query()
            ->where('server_id', $this->server->id)
            ->whereKey($schedule->target_id)
            ->first();
        if ($site === null) {
            $this->toastError(__('Schedule target site is missing.'));

            return;
        }

        $backup = SiteFileBackup::create([
            'site_id' => $site->id,
            'user_id' => auth()->id(),
            'status' => SiteFileBackup::STATUS_PENDING,
        ]);
        ExportSiteFileBackupJob::dispatch($backup->id);
        $this->toastSuccess(__('Backup queued for :name.', ['name' => $site->name]));
    }

    /**
     * Pause/resume a schedule by flipping is_active on both the schedule row and the
     * backing cron entry. The cron line stays in place so resume is one click.
     */
    public function toggleSchedule(string $scheduleId): void
    {
        $this->authorize('update', $this->server);

        $schedule = ServerBackupSchedule::query()
            ->where('server_id', $this->server->id)
            ->whereKey($scheduleId)
            ->first();
        if ($schedule === null) {
            return;
        }

        $newActive = ! $schedule->is_active;
        $schedule->update(['is_active' => $newActive]);
        if ($schedule->server_cron_job_id) {
            ServerCronJob::query()->whereKey($schedule->server_cron_job_id)->update(['enabled' => $newActive]);
        }

        if ($org = $this->server->organization) {
            audit_log(
                $org,
                auth()->user(),
                $newActive ? 'backup.schedule.resumed' : 'backup.schedule.paused',
                $schedule,
            );
        }

        $this->toastSuccess($newActive ? __('Schedule resumed.') : __('Schedule paused.'));
    }

    public function deleteDatabaseBackup(string $backupId): void
    {
        $this->authorize('update', $this->server);

        $backup = ServerDatabaseBackup::query()
            ->whereKey($backupId)
            ->whereHas('serverDatabase', fn ($q) => $q->where('server_id', $this->server->id))
            ->first();
        if ($backup === null) {
            return;
        }

        app(DatabaseBackupExporter::class)->deleteArtifact($backup);
        $snapshot = [
            'backup_id' => (string) $backup->id,
            'server_database_id' => (string) $backup->server_database_id,
            'storage_kind' => $backup->storage_kind,
            'status' => $backup->status,
        ];
        $backup->delete();

        if ($this->server->organization) {
            audit_log($this->server->organization, auth()->user(), 'backup.database.deleted', $this->server, $snapshot, null);
        }

        $this->toastSuccess(__('Backup deleted.'));
    }

    public function deleteFileBackup(string $backupId): void
    {
        $this->authorize('update', $this->server);

        $backup = SiteFileBackup::query()
            ->whereKey($backupId)
            ->whereHas('site', fn ($q) => $q->where('server_id', $this->server->id))
            ->first();
        if ($backup === null) {
            return;
        }

        if (! empty($backup->disk_path) && Storage::disk('local')->exists($backup->disk_path)) {
            Storage::disk('local')->delete($backup->disk_path);
        }
        $snapshot = [
            'backup_id' => (string) $backup->id,
            'site_id' => (string) $backup->site_id,
            'disk_path' => $backup->disk_path,
            'status' => $backup->status,
        ];
        $backup->delete();

        if ($this->server->organization) {
            audit_log($this->server->organization, auth()->user(), 'backup.site_files.deleted', $this->server, $snapshot, null);
        }

        $this->toastSuccess(__('Backup deleted.'));
    }

    public function render(): View
    {
        if ($this->comingSoonPreview) {
            $contextSite = $this->context_site_id !== null
                ? Site::query()->where('server_id', $this->server->id)->whereKey($this->context_site_id)->first()
                : null;

            return view('livewire.servers.workspace-backups-preview', [
                'contextSite' => $contextSite,
                'siteDedicatedContext' => $this->siteDedicatedContext,
            ]);
        }

        // No $this->server->refresh() here — route binding (and Livewire hydration)
        // already load the row fresh, and saveDatabaseBackupSettings() refreshes after
        // its own write. A blanket refresh on every render just duplicated the
        // `select * from servers` query that route binding already ran.

        // Note: $databases/$sites are NOT narrowed by context_site_id — the new-schedule form
        // still needs them all to pick from. The query collections below DO narrow so the
        // operator only sees runs / schedules / stats for the focused site.
        $databases = ServerDatabase::query()
            ->where('server_id', $this->server->id)
            ->orderBy('name')
            ->get();

        $sites = Site::query()
            ->where('server_id', $this->server->id)
            ->orderBy('name')
            ->get();

        // When filtered to a single site, database backups go away entirely (databases are
        // server-scoped, not site-scoped) — we narrow $databaseIds to an empty list so the
        // DB run list reads as "none for this site" rather than the full server fleet.
        $databaseIds = $this->context_site_id !== null ? [] : $databases->pluck('id')->all();
        $siteIds = $this->context_site_id !== null
            ? [$this->context_site_id]
            : $sites->pluck('id')->all();

        $databaseBackups = ServerDatabaseBackup::query()
            ->whereIn('server_database_id', $databaseIds)
            ->with('serverDatabase')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $fileBackups = SiteFileBackup::query()
            ->whereIn('site_id', $siteIds)
            ->with('site')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        // Schedules narrow to those targeting this site (site_files target_type with matching
        // target_id). Database schedules don't surface in site-context because databases
        // belong to the server, not to any one site.
        $schedules = ServerBackupSchedule::query()
            ->where('server_id', $this->server->id)
            ->when($this->context_site_id !== null, function ($q): void {
                $q->where('target_type', ServerBackupSchedule::TARGET_SITE_FILES)
                    ->where('target_id', $this->context_site_id);
            })
            ->orderBy('created_at')
            ->get();

        // Per-schedule "next run" + most recent status, computed once and passed by id.
        // Next-run parsing uses the same dragonmantank/cron-expression library Laravel
        // ships with; an unparseable expression silently degrades to null (the schedule
        // form rejects garbage at save time, so this is just defensive).
        $scheduleMeta = [];
        foreach ($schedules as $schedule) {
            $next = null;
            try {
                if ($schedule->is_active) {
                    $next = (new CronExpression($schedule->cron_expression))->getNextRunDate('now');
                }
            } catch (\Throwable) {
                $next = null;
            }

            $latestStatus = match ($schedule->target_type) {
                'database' => ServerDatabaseBackup::query()
                    ->where('server_database_id', $schedule->target_id)
                    ->orderByDesc('created_at')
                    ->value('status'),
                'site_files' => SiteFileBackup::query()
                    ->where('site_id', $schedule->target_id)
                    ->orderByDesc('created_at')
                    ->value('status'),
                default => null,
            };

            $recentRuns = match ($schedule->target_type) {
                'database' => ServerDatabaseBackup::query()
                    ->where('server_database_id', $schedule->target_id)
                    ->orderByDesc('created_at')
                    ->limit(5)
                    ->get(['id', 'status', 'bytes', 'created_at', 'error_message']),
                'site_files' => SiteFileBackup::query()
                    ->where('site_id', $schedule->target_id)
                    ->orderByDesc('created_at')
                    ->limit(5)
                    ->get(['id', 'status', 'bytes', 'created_at', 'error_message']),
                default => collect(),
            };

            $scheduleMeta[$schedule->id] = [
                'next_run_at' => $next,
                'latest_status' => $latestStatus,
                'recent_runs' => $recentRuns,
            ];
        }

        // Destinations are org-scoped — every server in the organization shares
        // the same set, so any teammate's bucket / rclone remote is immediately
        // pickable here without re-entering credentials.
        $backupConfigurations = $this->server->organization
            ? $this->server->organization->backupConfigurations()->orderBy('name')->get()
            : collect();

        // 7-day at-a-glance counts to help operators spot drift without scrolling. Pulled
        // separately from the recent-runs lists (which are capped at 20) so the metrics are
        // accurate even when there's a heavy backup cadence.
        $weekAgo = now()->subDays(7);
        $stats = [
            'db_completed_7d' => ServerDatabaseBackup::query()
                ->whereIn('server_database_id', $databaseIds)
                ->where('status', 'completed')
                ->where('created_at', '>=', $weekAgo)
                ->count(),
            'db_failed_7d' => ServerDatabaseBackup::query()
                ->whereIn('server_database_id', $databaseIds)
                ->where('status', 'failed')
                ->where('created_at', '>=', $weekAgo)
                ->count(),
            'files_completed_7d' => SiteFileBackup::query()
                ->whereIn('site_id', $siteIds)
                ->where('status', 'completed')
                ->where('created_at', '>=', $weekAgo)
                ->count(),
            'files_failed_7d' => SiteFileBackup::query()
                ->whereIn('site_id', $siteIds)
                ->where('status', 'failed')
                ->where('created_at', '>=', $weekAgo)
                ->count(),
            'total_bytes' => (int) ServerDatabaseBackup::query()
                ->whereIn('server_database_id', $databaseIds)
                ->where('status', 'completed')
                ->sum('bytes')
                + (int) SiteFileBackup::query()
                    ->whereIn('site_id', $siteIds)
                    ->where('status', 'completed')
                    ->sum('bytes'),
        ];

        $contextSite = $this->context_site_id !== null
            ? $sites->firstWhere('id', $this->context_site_id)
            : null;

        return view('livewire.servers.workspace-backups', [
            'opsReady' => $this->serverOpsReady(),
            'contextSite' => $contextSite,
            'siteDedicatedContext' => $this->siteDedicatedContext,
            'databases' => $databases,
            'sites' => $sites,
            'databaseBackups' => $databaseBackups,
            'fileBackups' => $fileBackups,
            'schedules' => $schedules,
            'scheduleMeta' => $scheduleMeta,
            'backupConfigurations' => $backupConfigurations,
            'stats' => $stats,
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
        ]);
    }
}
