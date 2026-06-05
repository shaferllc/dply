<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Livewire\Servers\WorkspaceDaemons;
use App\Models\Server;
use App\Models\Site;
use App\Models\SupervisorProgram;
use App\Services\Servers\LaravelQueueWorkCommandBuilder;
use App\Services\Servers\SupervisorDaemonAudit;
use App\Services\Servers\SupervisorProvisioner;
use App\Support\SupervisorEnvFormatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Shared Supervisor program form, presets, CRUD, install, and sync for
 * {@see WorkspaceDaemons} and
 * {@see WorkspaceDaemons}.
 */
trait ManagesSupervisorPrograms
{
    public string $new_sv_slug = '';

    public string $new_sv_type = 'queue';

    public string $new_sv_command = 'php artisan queue:work --sleep=3 --tries=3';

    public string $new_sv_directory = '';

    public string $new_sv_user = 'dply';

    public int $new_sv_numprocs = 1;

    public string $new_sv_env_lines = '';

    public string $new_sv_stdout_logfile = '';

    public ?string $new_sv_site_id = null;

    /**
     * @var 'quick'|'advanced'
     */
    public string $queue_builder_mode = 'advanced';

    public string $quick_php_binary = 'php';

    public string $quick_queue_connection = '';

    public string $quick_queue_name = 'default';

    public int $quick_timeout = 60;

    public int $quick_sleep = 3;

    public int $quick_tries = 3;

    public int $quick_backoff = 0;

    public int $quick_memory = 128;

    public int $quick_max_time = 3600;

    public string $quick_app_env = 'production';

    /** When set (site route or ?site=), scope defaults and optional site lock. */
    public ?string $context_site_id = null;

    public ?int $new_sv_priority = null;

    public ?int $new_sv_startsecs = null;

    public ?int $new_sv_stopwaitsecs = null;

    public string $new_sv_autorestart = '';

    public bool $new_sv_redirect_stderr = true;

    public string $new_sv_stderr_logfile = '';

    public ?string $editing_program_id = null;

    public string $last_supervisor_sync_output = '';

    /**
     * @return null|list<string> null = all program types allowed (Daemons).
     */
    protected function allowedSupervisorProgramTypes(): ?array
    {
        return null;
    }

    /**
     * @return list<string>
     */
    protected function allowedSupervisorPresetKeys(): array
    {
        $laravelKeys = ['laravel-queue', 'laravel-horizon', 'reverb', 'laravel-schedule', 'laravel-octane'];
        $railsKeys = ['sidekiq', 'solid-queue', 'action-cable'];

        $site = $this->supervisorFormSite();

        // No site context: server-wide daemon, show everything.
        if ($site === null) {
            return array_merge($laravelKeys, ['nodejs'], $railsKeys);
        }

        $detection = $site->resolvedRuntimeAppDetection();

        // No detection data yet (site hasn't deployed): default to PHP/Laravel.
        if ($detection === null) {
            return $laravelKeys;
        }

        $language = strtolower((string) ($detection['language'] ?? ''));

        if ($site->isRailsFrameworkDetected()) {
            return $railsKeys;
        }

        if ($language === 'node') {
            return ['nodejs'];
        }

        if ($site->isLaravelFrameworkDetected() || $language === 'php') {
            return $laravelKeys;
        }

        // Unknown/unsupported framework: show everything.
        return array_merge($laravelKeys, ['nodejs'], $railsKeys);
    }

    public function supervisorFormSite(): ?Site
    {
        $siteId = $this->context_site_id
            ?: (($this->new_sv_site_id !== null && $this->new_sv_site_id !== '') ? $this->new_sv_site_id : null);

        if ($siteId === null) {
            return null;
        }

        return Site::query()
            ->where('server_id', $this->server->id)
            ->whereKey($siteId)
            ->first();
    }

    public function supervisorFormSiteIsLaravel(): bool
    {
        return $this->supervisorFormSite()?->isLaravelFrameworkDetected() ?? false;
    }

    public function updatedNewSvSiteId(): void
    {
        if ($this->new_sv_type === 'queue' && ! $this->supervisorFormSiteIsLaravel()) {
            $this->queue_builder_mode = 'advanced';
        }
    }

    protected function supervisorProgramModalName(): string
    {
        return 'daemon-program-modal';
    }

    protected function supervisorProgramsLockSiteId(): bool
    {
        return false;
    }

    protected function defaultNewProgramType(): string
    {
        return 'custom';
    }

    protected function initSupervisorProgramFormDefaults(): void
    {
        $this->new_sv_user = $this->defaultProgramUser();
        $this->new_sv_directory = $this->defaultProgramDirectory();
        $this->resetDefaultsForNewProgramForm();
    }

    public function updatedNewSvType(string $value): void
    {
        if ($value !== 'queue') {
            $this->queue_builder_mode = 'advanced';
        }
    }

    public function applySupervisorPreset(string $preset): void
    {
        $this->authorize('update', $this->server);

        if (! in_array($preset, $this->allowedSupervisorPresetKeys(), true)) {
            $this->toastError(__('That preset is not available on this page.'));

            return;
        }

        match ($preset) {
            'laravel-queue' => $this->applyLaravelQueuePresetQuick(),
            'laravel-horizon' => $this->applySupervisorPresetValues(
                'laravel-horizon',
                'horizon',
                'php artisan horizon',
                $this->defaultAppDirectory()
            ),
            'reverb' => $this->applySupervisorPresetValues(
                'laravel-reverb',
                'reverb',
                'php artisan reverb:start',
                $this->defaultAppDirectory()
            ),
            'laravel-schedule' => $this->applySupervisorPresetValues(
                'laravel-schedule',
                'custom',
                'php artisan schedule:work',
                $this->defaultAppDirectory()
            ),
            'laravel-octane' => $this->applyLaravelOctanePreset(),
            'nodejs' => $this->applySupervisorPresetValues(
                'nodejs-app',
                'custom',
                'node server.js',
                $this->defaultAppDirectory()
            ),
            'sidekiq' => $this->applySupervisorPresetValues(
                'sidekiq',
                'sidekiq',
                'bundle exec sidekiq -C config/sidekiq.yml',
                $this->defaultAppDirectory()
            ),
            'solid-queue' => $this->applySupervisorPresetValues(
                'solid-queue',
                'solid-queue',
                'bin/jobs',
                $this->defaultAppDirectory()
            ),
            'action-cable' => $this->applySupervisorPresetValues(
                'action-cable',
                'custom',
                'bundle exec puma -p 28080 cable/config.ru',
                $this->defaultAppDirectory()
            ),
            default => null,
        };

        $this->toastSuccess(__('Preset loaded — adjust directory if needed, then add the program.'));
    }

    public function applyQueuePresetAndOpenModal(string $preset): void
    {
        $this->applySupervisorPreset($preset);
        $this->dispatch('open-modal', $this->supervisorProgramModalName());
    }

    protected function applyLaravelQueuePresetQuick(): void
    {
        $this->queue_builder_mode = 'quick';
        $this->quick_php_binary = 'php';
        $this->quick_queue_connection = '';
        $this->quick_queue_name = 'default';
        $this->quick_timeout = 60;
        $this->quick_sleep = 3;
        $this->quick_tries = 3;
        $this->quick_backoff = 0;
        $this->quick_memory = 128;
        $this->quick_max_time = 3600;
        $this->quick_app_env = 'production';
        $this->new_sv_type = 'queue';
        $this->new_sv_slug = 'laravel-queue';
        $this->new_sv_numprocs = 1;
        $this->resetExpertFormFields();

        $dir = $this->defaultAppDirectory();
        $user = $this->defaultProgramUser();
        if ($this->new_sv_site_id !== null && $this->new_sv_site_id !== '') {
            $site = Site::query()
                ->where('server_id', $this->server->id)
                ->find($this->new_sv_site_id);
            if ($site !== null) {
                $dir = rtrim($site->effectiveRepositoryPath(), '/').'/current';
                $user = $site->effectiveSystemUser($this->server);
            }
        }
        $this->new_sv_directory = $dir;
        $this->new_sv_user = $user;

        $this->new_sv_command = (new LaravelQueueWorkCommandBuilder(
            phpBinary: $this->quick_php_binary,
            connection: $this->quick_queue_connection,
            queue: $this->quick_queue_name,
            timeout: $this->quick_timeout,
            sleep: $this->quick_sleep,
            tries: $this->quick_tries,
            backoff: $this->quick_backoff,
            memory: $this->quick_memory,
            maxTime: $this->quick_max_time,
        ))->build();
    }

    protected function applyLaravelOctanePreset(): void
    {
        $command = 'php artisan octane:start --server=swoole --host=127.0.0.1 --port=8000';
        $directory = $this->defaultAppDirectory();

        if ($this->new_sv_site_id !== null && $this->new_sv_site_id !== '') {
            $site = Site::query()
                ->where('server_id', $this->server->id)
                ->find($this->new_sv_site_id);
            if ($site !== null && $site->shouldShowOctaneRuntimeUi()) {
                $command = $site->octaneSupervisorCommand();
                $directory = rtrim($site->effectiveRepositoryPath(), '/').'/current';
            }
        }

        $this->applySupervisorPresetValues(
            'laravel-octane',
            'octane',
            $command,
            $directory
        );
    }

    protected function applySupervisorPresetValues(string $slug, string $type, string $command, string $directory): void
    {
        $this->queue_builder_mode = 'advanced';
        $this->new_sv_slug = $slug;
        $this->new_sv_type = $type;
        $this->new_sv_command = $command;
        $this->new_sv_directory = $directory;
        $this->new_sv_user = $this->defaultProgramUser();
        $this->new_sv_numprocs = 1;
        $this->resetExpertFormFields();
    }

    protected function resetExpertFormFields(): void
    {
        $this->new_sv_priority = null;
        $this->new_sv_startsecs = null;
        $this->new_sv_stopwaitsecs = null;
        $this->new_sv_autorestart = '';
        $this->new_sv_redirect_stderr = true;
        $this->new_sv_stderr_logfile = '';
    }

    /**
     * @return array<string, mixed>
     */
    protected function rulesForProgramFormQuickQueue(): array
    {
        return [
            'quick_php_binary' => ['required', 'string', 'max:128'],
            'quick_queue_connection' => ['nullable', 'string', 'max:64'],
            'quick_queue_name' => ['required', 'string', 'max:128'],
            'quick_timeout' => ['required', 'integer', 'min:1', 'max:86400'],
            'quick_sleep' => ['required', 'integer', 'min:0', 'max:3600'],
            'quick_tries' => ['required', 'integer', 'min:0', 'max:100'],
            'quick_backoff' => ['required', 'integer', 'min:0', 'max:86400'],
            'quick_memory' => ['required', 'integer', 'min:16', 'max:8192'],
            'quick_max_time' => ['required', 'integer', 'min:0', 'max:86400'],
            'quick_app_env' => ['nullable', 'string', 'max:64'],
        ];
    }

    protected function defaultProgramUser(): string
    {
        $candidate = (string) config('server_provision.deploy_ssh_user', 'dply');
        $candidate = trim($candidate) !== '' ? trim($candidate) : 'dply';

        $serverUser = trim((string) ($this->server->ssh_user ?? ''));
        if ($serverUser !== '' && $serverUser !== 'root') {
            return $serverUser;
        }

        return $candidate;
    }

    protected function defaultProgramDirectory(): string
    {
        return '/home/'.$this->defaultProgramUser();
    }

    protected function defaultAppDirectory(): string
    {
        $user = $this->defaultProgramUser();
        $name = trim((string) ($this->server->name ?? '')) !== ''
            ? Str::slug($this->server->name)
            : 'app';

        return '/home/'.$user.'/apps/'.$name.'/current';
    }

    /**
     * @return array<string, mixed>
     */
    protected function rulesForProgramForm(): array
    {
        return [
            'new_sv_slug' => 'required|string|max:64|regex:/^[a-z0-9\-]+$/',
            'new_sv_command' => 'required|string|max:2000',
            'new_sv_directory' => 'required|string|max:512',
            'new_sv_user' => 'required|string|max:64',
            'new_sv_numprocs' => 'required|integer|min:1|max:32',
            'new_sv_env_lines' => 'nullable|string|max:12000',
            'new_sv_stdout_logfile' => 'nullable|string|max:512',
            'new_sv_site_id' => [
                'nullable',
                'ulid',
                Rule::exists('sites', 'id')->where(fn ($q) => $q->where('server_id', $this->server->id)),
            ],
            'new_sv_priority' => 'nullable|integer|min:1|max:999',
            'new_sv_startsecs' => 'nullable|integer|min:0|max:3600',
            'new_sv_stopwaitsecs' => 'nullable|integer|min:0|max:86400',
            'new_sv_autorestart' => 'nullable|string|max:32',
            'new_sv_redirect_stderr' => 'boolean',
            'new_sv_stderr_logfile' => 'nullable|string|max:512',
        ];
    }

    protected function resolveSiteForQueueBuilder(): ?Site
    {
        $id = $this->new_sv_site_id ?: $this->context_site_id;
        if ($id === null || $id === '') {
            return null;
        }

        return Site::query()->where('server_id', $this->server->id)->whereKey($id)->first();
    }

    protected function syncQuickQueueBuilderIntoForm(): bool
    {
        if ($this->queue_builder_mode !== 'quick' || $this->new_sv_type !== 'queue') {
            return true;
        }

        $this->validate($this->rulesForProgramFormQuickQueue());

        $site = $this->resolveSiteForQueueBuilder();
        if ($site === null) {
            $this->addError('new_sv_site_id', __('Select a related site so Dply can set the app directory and system user for this worker.'));

            return false;
        }

        $this->new_sv_command = (new LaravelQueueWorkCommandBuilder(
            phpBinary: $this->quick_php_binary,
            connection: $this->quick_queue_connection,
            queue: $this->quick_queue_name,
            timeout: $this->quick_timeout,
            sleep: $this->quick_sleep,
            tries: $this->quick_tries,
            backoff: $this->quick_backoff,
            memory: $this->quick_memory,
            maxTime: $this->quick_max_time,
        ))->build();

        $this->new_sv_directory = rtrim($site->effectiveRepositoryPath(), '/').'/current';
        $this->new_sv_user = $site->effectiveSystemUser($this->server);
        $this->new_sv_site_id = $site->id;

        if (trim($this->new_sv_slug) === '') {
            $this->new_sv_slug = 'laravel-queue-'.Str::slug($this->quick_queue_name !== '' ? $this->quick_queue_name : 'default');
        }

        $env = SupervisorEnvFormatter::parseLines($this->new_sv_env_lines);
        if (trim($this->quick_app_env) !== '') {
            $env['APP_ENV'] = trim($this->quick_app_env);
        }
        $lines = [];
        foreach ($env as $k => $v) {
            $lines[] = $k.'='.$v;
        }
        $this->new_sv_env_lines = implode("\n", $lines);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function programAttributesFromForm(): array
    {
        if ($this->supervisorProgramsLockSiteId() && $this->context_site_id !== null) {
            $this->new_sv_site_id = $this->context_site_id;
        }

        $env = SupervisorEnvFormatter::parseLines($this->new_sv_env_lines);

        return [
            'site_id' => $this->new_sv_site_id !== null && $this->new_sv_site_id !== '' ? $this->new_sv_site_id : null,
            'slug' => $this->new_sv_slug,
            'program_type' => $this->new_sv_type,
            'command' => $this->new_sv_command,
            'directory' => $this->new_sv_directory,
            'user' => $this->new_sv_user,
            'numprocs' => $this->new_sv_numprocs,
            'is_active' => true,
            'env_vars' => $env === [] ? null : $env,
            'stdout_logfile' => $this->new_sv_stdout_logfile !== '' ? $this->new_sv_stdout_logfile : null,
            'priority' => $this->new_sv_priority,
            'startsecs' => $this->new_sv_startsecs,
            'stopwaitsecs' => $this->new_sv_stopwaitsecs,
            'autorestart' => $this->new_sv_autorestart !== '' ? $this->new_sv_autorestart : null,
            'redirect_stderr' => $this->new_sv_redirect_stderr,
            'stderr_logfile' => $this->new_sv_stderr_logfile !== '' ? $this->new_sv_stderr_logfile : null,
        ];
    }

    protected function assertProgramTypeAllowed(string $programType): void
    {
        $allowed = $this->allowedSupervisorProgramTypes();
        if ($allowed === null) {
            return;
        }

        if (! in_array($programType, $allowed, true)) {
            throw ValidationException::withMessages([
                'new_sv_command' => [__('This page only supports queue-class workers. Use Daemons for other program types.')],
            ]);
        }
    }

    protected function supervisorProgramQuery(): Builder
    {
        $query = SupervisorProgram::query()->where('server_id', $this->server->id);
        $allowed = $this->allowedSupervisorProgramTypes();
        if ($allowed !== null) {
            $query->whereIn('program_type', $allowed);
        }

        return $query;
    }

    public function saveSupervisorProgram(): void
    {
        $this->authorize('update', $this->server);
        if (! $this->syncQuickQueueBuilderIntoForm()) {
            return;
        }

        $this->validate($this->rulesForProgramForm());

        $attrs = $this->programAttributesFromForm();
        $this->assertProgramTypeAllowed((string) $attrs['program_type']);

        $modal = $this->supervisorProgramModalName();

        if ($this->editing_program_id !== null) {
            $prog = $this->supervisorProgramQuery()
                ->whereKey($this->editing_program_id)
                ->first();
            if (! $prog) {
                $this->toastError(__('Program not found.'));
                $this->cancelEditProgram();

                return;
            }
            $oldSnapshot = [
                'slug' => $prog->slug,
                'program_type' => $prog->program_type,
                'command' => $prog->command,
                'directory' => $prog->directory,
                'user' => $prog->user,
                'numprocs' => $prog->numprocs,
                'site_id' => $prog->site_id,
            ];
            $prog->update($attrs);
            SupervisorDaemonAudit::log($this->server->fresh(), $prog->fresh(), 'program_updated', [
                'old' => $oldSnapshot,
                'new' => array_intersect_key($attrs, $oldSnapshot),
            ]);
            $this->toastSuccess(__('Program updated. Sync Supervisor on the server to apply changes.'));
            $this->cancelEditProgram();
            $this->dispatch('close-modal', $modal);
        } else {
            $type = $this->new_sv_type;
            $nproc = $this->new_sv_numprocs;
            $created = SupervisorProgram::query()->create(array_merge($attrs, [
                'server_id' => $this->server->id,
            ]));
            SupervisorDaemonAudit::log($this->server->fresh(), $created->fresh(), 'program_created', [
                'slug' => $created->slug,
                'program_type' => $created->program_type,
                'numprocs' => $created->numprocs,
                'site_id' => $created->site_id,
            ]);
            $this->cancelEditProgram();
            $this->resetDefaultsForNewProgramForm();
            $msg = __('Program saved. Sync Supervisor on the server to apply changes.');
            if ($type === 'horizon' && $nproc > 1) {
                $msg .= ' '.__('Note: Horizon usually runs with numprocs 1; scaling is typically done inside Horizon.');
            }
            if ($type === 'queue' && $nproc > 4) {
                $msg .= ' '.__('Note: Many queue workers are often better as separate programs or Horizon.');
            }
            $this->toastSuccess($msg);
            $this->dispatch('close-modal', $modal);
        }
    }

    protected function resetDefaultsForNewProgramForm(): void
    {
        $this->new_sv_type = $this->defaultNewProgramType();
        $this->queue_builder_mode = 'advanced';
        $this->quick_php_binary = 'php';
        $this->quick_queue_connection = '';
        $this->quick_queue_name = 'default';
        $this->quick_timeout = 60;
        $this->quick_sleep = 3;
        $this->quick_tries = 3;
        $this->quick_backoff = 0;
        $this->quick_memory = 128;
        $this->quick_max_time = 3600;
        $this->quick_app_env = 'production';
        $this->new_sv_command = 'php artisan queue:work --sleep=3 --tries=3';
        $this->new_sv_directory = $this->defaultProgramDirectory();
        $this->new_sv_user = $this->defaultProgramUser();
        $this->new_sv_numprocs = 1;
        $this->new_sv_site_id = $this->context_site_id;
        if ($this->context_site_id !== null && ($ctx = Site::query()->where('server_id', $this->server->id)->whereKey($this->context_site_id)->first())) {
            $this->new_sv_directory = rtrim($ctx->effectiveRepositoryPath(), '/').'/current';
            $this->new_sv_user = $ctx->effectiveSystemUser($this->server);
        }
    }

    public function beginEditProgram(string $id): void
    {
        $this->authorize('update', $this->server);
        $prog = $this->supervisorProgramQuery()->whereKey($id)->first();
        if (! $prog) {
            return;
        }
        $this->editing_program_id = $prog->id;
        $this->new_sv_slug = $prog->slug;
        $this->new_sv_type = $prog->program_type;
        $this->new_sv_command = $prog->command;
        $this->new_sv_directory = $prog->directory;
        $this->new_sv_user = $prog->user;
        $this->new_sv_numprocs = (int) $prog->numprocs;
        $this->new_sv_site_id = $prog->site_id;
        $this->new_sv_stdout_logfile = $prog->stdout_logfile ?? '';
        $this->new_sv_stderr_logfile = $prog->stderr_logfile ?? '';
        $this->new_sv_priority = $prog->priority;
        $this->new_sv_startsecs = $prog->startsecs;
        $this->new_sv_stopwaitsecs = $prog->stopwaitsecs;
        $this->new_sv_autorestart = $prog->autorestart ?? '';
        $this->new_sv_redirect_stderr = $prog->redirect_stderr ?? true;
        $env = is_array($prog->env_vars) ? $prog->env_vars : [];
        $lines = [];
        foreach ($env as $k => $v) {
            $lines[] = $k.'='.$v;
        }
        $this->new_sv_env_lines = implode("\n", $lines);
        $this->queue_builder_mode = 'advanced';
        $this->dispatch('open-modal', $this->supervisorProgramModalName());
        $this->toastSuccess(__('Editing — change fields and save.'));
    }

    public function cancelEditProgram(): void
    {
        $this->editing_program_id = null;
        $this->new_sv_slug = '';
        $this->new_sv_stdout_logfile = '';
        $this->resetDefaultsForNewProgramForm();
        $this->new_sv_env_lines = '';
        $this->resetExpertFormFields();
    }

    public function openCreateSupervisorProgramModal(): void
    {
        $this->authorize('update', $this->server);
        $this->resetErrorBag();
        $this->cancelEditProgram();
        $this->dispatch('open-modal', $this->supervisorProgramModalName());
    }

    public function closeCreateSupervisorProgramModal(): void
    {
        $this->resetErrorBag();
        $this->cancelEditProgram();
        $this->dispatch('close-modal', $this->supervisorProgramModalName());
    }

    public function openCreateDaemonModal(): void
    {
        $this->openCreateSupervisorProgramModal();
    }

    /**
     * Open the create-daemon modal pre-filled from a preset — the one-click
     * target for {@see \App\Support\Sites\SiteDaemonAdvisor} suggestions. We
     * pin new_sv_site_id to the page's context site first so the preset is
     * allowed (allowedSupervisorPresetKeys() is site-derived) and the command
     * picks up the site's directory/user.
     */
    public function suggestDaemonPreset(string $preset): void
    {
        $this->authorize('update', $this->server);
        $this->openCreateSupervisorProgramModal();
        if (($this->context_site_id ?? '') !== '') {
            $this->new_sv_site_id = (string) $this->context_site_id;
        }
        $this->applySupervisorPreset($preset);
    }

    public function closeCreateDaemonModal(): void
    {
        $this->closeCreateSupervisorProgramModal();
    }

    public function deleteSupervisorProgram(string $id, SupervisorProvisioner $provisioner): void
    {
        $this->authorize('update', $this->server);
        $prog = $this->supervisorProgramQuery()->whereKey($id)->first();
        if ($prog) {
            $snapshot = [
                'slug' => $prog->slug,
                'program_type' => $prog->program_type,
                'command' => $prog->command,
                'directory' => $prog->directory,
                'user' => $prog->user,
                'numprocs' => $prog->numprocs,
                'site_id' => $prog->site_id,
            ];
            $provisioner->deleteConfigFile($this->server, $prog->id);
            $prog->delete();
            SupervisorDaemonAudit::log($this->server->fresh(), null, 'program_deleted', $snapshot);
        }
        if ($this->editing_program_id === $id) {
            $this->cancelEditProgram();
        }
        $this->toastSuccess(__('Removed. Sync Supervisor to reload on the server.'));
    }

    public function installSupervisorPackage(SupervisorProvisioner $provisioner): void
    {
        $this->authorize('update', $this->server);
        try {
            $out = $provisioner->installSupervisorPackage($this->server->fresh());
            $this->last_supervisor_sync_output = trim($out);
            $this->server->refresh();
            $this->supervisor_installed = $provisioner->isSupervisorPackageInstalled($this->server->fresh());
            if ($this->supervisor_installed) {
                $this->server->update(['supervisor_package_status' => Server::SUPERVISOR_PACKAGE_INSTALLED]);
            } else {
                $this->server->update(['supervisor_package_status' => Server::SUPERVISOR_PACKAGE_MISSING]);
            }
            SupervisorDaemonAudit::log($this->server->fresh(), null, 'package_install_attempted', [
                'installed' => (bool) $this->supervisor_installed,
                'output' => Str::limit($out, 1000),
            ]);
            $this->toastSuccess(__('Supervisor was installed on the server. You can add programs and sync.'));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
            $this->supervisor_installed = false;
        }
    }

    public function syncSupervisor(SupervisorProvisioner $provisioner): void
    {
        $this->authorize('update', $this->server);
        try {
            $this->server->refresh();
            $out = $provisioner->sync($this->server);
            $trimmed = trim($out);
            $this->last_supervisor_sync_output = $trimmed;
            SupervisorDaemonAudit::log($this->server->fresh(), null, 'supervisor_sync', ['output' => Str::limit($trimmed, 2000)]);
            $this->toastSuccess(__('Supervisor sync: :snippet', ['snippet' => Str::limit($trimmed, 800)]));
            $this->supervisor_installed = true;
            $this->server->update(['supervisor_package_status' => Server::SUPERVISOR_PACKAGE_INSTALLED]);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());
        }
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function supervisorPresetOptionsForForm(): array
    {
        $all = [
            ['value' => 'laravel-queue', 'label' => __('Laravel queue worker (queue:work)')],
            ['value' => 'laravel-horizon', 'label' => __('Laravel Horizon')],
            ['value' => 'reverb', 'label' => __('Laravel Reverb (websockets)')],
            ['value' => 'laravel-schedule', 'label' => __('Laravel scheduler (schedule:work)')],
            ['value' => 'laravel-octane', 'label' => __('Laravel Octane')],
            ['value' => 'nodejs', 'label' => __('Node.js process')],
            ['value' => 'sidekiq', 'label' => __('Sidekiq (Ruby)')],
            ['value' => 'solid-queue', 'label' => __('Solid Queue (Rails 8)')],
            ['value' => 'action-cable', 'label' => __('Action Cable (Rails websockets)')],
        ];

        $allowed = $this->allowedSupervisorPresetKeys();

        return array_values(array_filter(
            $all,
            static fn (array $row): bool => in_array($row['value'], $allowed, true)
        ));
    }
}
