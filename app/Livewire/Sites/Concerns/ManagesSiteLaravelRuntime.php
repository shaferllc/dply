<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Models\Site;
use App\Models\Snapshot;
use App\Modules\RemoteCli\Services\Artisan;
use App\Modules\RemoteCli\Services\RemoteCliPermissionDeniedException;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Services\Sites\LaravelConsoleExecutor;
use App\Services\Sites\LaravelSiteSshSetupRunner;
use App\Services\Sites\SiteScopedCommandWrapper;
use App\Services\Snapshots\LocalDiskDestination;
use App\Services\Snapshots\SnapshotService;
use App\Services\SshConnection;
use Illuminate\Validation\Rule;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSiteLaravelRuntime
{
    public ?string $laravel_ssh_setup_pending_action = null;

    public ?string $laravel_ssh_setup_error = null;

    /** @var 'commands'|'octane'|'reverb'|'logs'|'setup'|'schedule'|'migrations'|'pail' */
    public string $laravel_tab = 'commands';

    /**
     * Schedule sub-tab: parsed `php artisan schedule:list --json` rows.
     * Empty until the operator clicks Load.
     *
     * @var list<array{command?: string, description?: string, expression?: string, next_due?: string}>
     */
    public array $laravelScheduleEntries = [];

    public bool $laravelScheduleLoaded = false;

    /**
     * Migrations sub-tab: parsed `php artisan migrate:status --json`
     * rows ordered as the framework returns them (oldest → newest).
     *
     * @var list<array{migration?: string, batch?: int|string|null, ran?: bool}>
     */
    public array $laravelMigrationEntries = [];

    public bool $laravelMigrationsLoaded = false;

    /** UI flash flag set after a successful pre-rollback snapshot+rollback. */
    public ?string $laravelMigrationsFlash = null;

    /**
     * Pail sub-tab buffer — recent log entries from storage/logs/laravel.log.
     *
     * v1 (PR 11c) shipped operator-driven "Tail logs" button.
     * v1.1 (this slice) adds wire:poll-driven live tail with byte-offset
     * tracking: every 2s we fetch only the bytes appended since the
     * last poll, append to the buffer client-side, and trim to a
     * cap so memory stays bounded. Real WebSocket/SSE streaming is
     * still a v2 concern but for typical Laravel sites with
     * single-line-per-event JSON logs this looks "live enough".
     */
    public string $laravelPailBuffer = '';

    public bool $laravelPailLoaded = false;

    /**
     * Byte offset into the log file we've already streamed up to.
     * Sent to `tail -c +<offset>` on each poll so we only ship new
     * bytes. Reset to 0 on Refresh so the user can re-baseline.
     */
    public int $laravelPailOffset = 0;

    /** Live-poll on/off — operator toggles on the sub-tab. */
    public bool $laravelPailLive = false;

    public string $laravel_custom_commands_text = '';

    /**
     * @var array{ok?: bool, commands?: list<array{name: string, description?: string|null}>, error?: string|null, raw?: string}
     */
    public array $laravel_artisan_discovery = [];

    public ?string $laravel_console_error = null;

    public int $laravel_log_tail_lines = 500;

    protected function syncLaravelConsoleForm(): void
    {
        $executor = app(LaravelConsoleExecutor::class);
        $this->laravel_custom_commands_text = implode("\n", $executor->customCommands($this->site));
    }

    public function updatedLaravelTab(string $value): void
    {
        if ($value === 'commands') {
            $this->loadLaravelArtisanDiscovery(false);
        }
    }

    public function loadLaravelArtisanDiscovery(bool $force = false): void
    {
        $this->authorize('view', $this->site);
        $executor = app(LaravelConsoleExecutor::class);
        $this->laravel_artisan_discovery = $executor->listArtisanCommands($this->site->fresh(), $force);
    }

    /**
     * Schedule sub-tab loader (PR 11).
     *
     * Runs `php artisan schedule:list --json` via the new Artisan
     * service (PR 1+2) — sync execution because schedule:list is on
     * the INSTANT allowlist. Parses the JSON output into a flat array
     * the schedule-tab partial renders. Failures land as inline errors
     * rather than throwing; broken parsing leaves the entry list empty
     * with a friendly message.
     */
    public function loadLaravelSchedule(Artisan $artisan): void
    {
        $this->authorize('view', $this->site);

        try {
            $result = $artisan->run(
                site: $this->site,
                command: 'schedule:list',
                args: ['--json'],
                queuedBy: auth()->user(),
            );
        } catch (RemoteCliPermissionDeniedException $e) {
            $this->addError('laravel_schedule', __('Your role can\'t inspect the Laravel schedule.'));

            return;
        }

        $stdout = trim($result->stdout());
        $rows = $stdout !== '' ? json_decode($stdout, associative: true) : [];

        $this->laravelScheduleEntries = is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
        $this->laravelScheduleLoaded = true;
    }

    /**
     * Migrations sub-tab loader (PR 11b).
     *
     * Runs `php artisan migrate:status --json` via the Artisan service.
     * The command is INSTANT-allowlisted so it returns inline. Parsed
     * rows feed the migrations-tab partial.
     */
    public function loadLaravelMigrations(Artisan $artisan): void
    {
        $this->authorize('view', $this->site);

        try {
            $result = $artisan->run(
                site: $this->site,
                command: 'migrate:status',
                args: ['--json'],
                queuedBy: auth()->user(),
            );
        } catch (RemoteCliPermissionDeniedException $e) {
            $this->addError('laravel_migrations', __('Your role can\'t inspect migrations.'));

            return;
        }

        $stdout = trim($result->stdout());
        $rows = $stdout !== '' ? json_decode($stdout, associative: true) : [];

        $this->laravelMigrationEntries = is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
        $this->laravelMigrationsLoaded = true;
    }

    /**
     * Rollback the most-recent N migration batches, optionally taking
     * a pre-rollback snapshot via SnapshotService for the safety net
     * (Q9 + Q19). Admin/owner only because this is destructive — losing
     * data without a snapshot to restore from is a real possibility.
     */
    public function rollbackLastMigrationBatch(
        Artisan $artisan,
        SnapshotService $snapshots,
        ExecuteRemoteTaskOnServer $executor,
    ): void {
        $this->authorize('update', $this->site);

        $org = $this->site->organization;
        if ($org === null || ! $org->hasAdminAccess(auth()->user())) {
            $this->addError('laravel_migrations', __('Admin or owner role required to roll back migrations.'));

            return;
        }

        // Pre-rollback safety-net snapshot to local disk (Q19 transient).
        try {
            $snapshot = $snapshots->take(
                site: $this->site,
                destination: new LocalDiskDestination($executor),
                reason: Snapshot::REASON_PRE_MIGRATION_ROLLBACK,
                userId: auth()->id(),
            );
        } catch (\Throwable $e) {
            $this->addError('laravel_migrations', __('Pre-rollback snapshot failed; aborting rollback. :err', ['err' => $e->getMessage()]));

            return;
        }

        try {
            $artisan->run(
                site: $this->site,
                command: 'migrate:rollback',
                args: ['--force', '--step=1'],
                queuedBy: auth()->user(),
            );
        } catch (RemoteCliPermissionDeniedException $e) {
            $this->addError('laravel_migrations', __('Permission denied: :err', ['err' => $e->getMessage()]));

            return;
        }

        $this->laravelMigrationsFlash = __('Rolled back last migration batch. Pre-rollback snapshot saved as snap-:id.', ['id' => $snapshot->id]);
        $this->laravelMigrationsLoaded = false; // Force reload to refresh status table
    }

    /**
     * Pail sub-tab loader.
     *
     * Initial load: fetches the last 200 lines + records the file's
     * current size as the byte offset to stream from. Subsequent calls
     * (driven by wire:poll when laravelPailLive is true OR by manual
     * Refresh) emit `tail -c +<offset+1>` so only the bytes appended
     * since last poll come back; appended to the buffer + trimmed at
     * PAIL_BUFFER_MAX_CHARS so chatty logs don't blow Livewire state.
     *
     * Real WebSocket/SSE streaming is still a v2 concern — wire:poll
     * is the right tradeoff for a logs panel where 2s latency is fine
     * and dply already has the polling primitive plumbed everywhere
     * (no new infra to maintain).
     */
    public function loadLaravelPail(ExecuteRemoteTaskOnServer $executor, int $lines = 200): void
    {
        $this->authorize('view', $this->site);

        $logPath = $this->laravelLogPath();
        $script = $this->laravelPailLoaded
            // Incremental fetch: send bytes after current offset, plus
            // the new file size so we can advance the offset locally.
            ? sprintf(
                'if [ -r %1$s ]; then SIZE=$(stat -c %%s %1$s 2>/dev/null || stat -f %%z %1$s); echo "DPLY-PAIL-SIZE:$SIZE"; tail -c +%2$d %1$s 2>/dev/null; else echo "DPLY-PAIL-MISSING"; fi',
                escapeshellarg($logPath),
                $this->laravelPailOffset + 1,
            )
            // First fetch: tail the last N lines AND record the file's
            // total size as the new offset so the next poll continues
            // from there.
            : sprintf(
                'if [ -r %1$s ]; then SIZE=$(stat -c %%s %1$s 2>/dev/null || stat -f %%z %1$s); echo "DPLY-PAIL-SIZE:$SIZE"; tail -n %2$d %1$s 2>/dev/null; else echo "DPLY-PAIL-MISSING"; fi',
                escapeshellarg($logPath),
                max(10, min(1000, $lines)),
            );

        try {
            $out = $executor->runInlineBash(
                server: $this->site->server,
                name: 'laravel:pail-tail',
                inlineBash: $script,
                timeoutSeconds: 15,
            );
        } catch (\Throwable $e) {
            $this->addError('laravel_pail', __('Pail tail failed: :err', ['err' => $e->getMessage()]));

            return;
        }

        $raw = (string) $out->getBuffer();

        if (str_starts_with(trim($raw), 'DPLY-PAIL-MISSING')) {
            $this->laravelPailBuffer = '(no log file at '.$logPath.')';
            $this->laravelPailLoaded = true;

            return;
        }

        // Strip the size header line and parse the new offset.
        if (preg_match('/^DPLY-PAIL-SIZE:(\d+)\n?/', $raw, $matches)) {
            $newOffset = (int) $matches[1];
            $body = substr($raw, strlen($matches[0]));
        } else {
            $newOffset = $this->laravelPailOffset;
            $body = $raw;
        }

        if ($this->laravelPailLoaded) {
            $this->laravelPailBuffer .= $body;
        } else {
            $this->laravelPailBuffer = $body;
            $this->laravelPailLoaded = true;
        }

        // Cap buffer so a chatty log doesn't blow up Livewire payload.
        if (strlen($this->laravelPailBuffer) > self::PAIL_BUFFER_MAX_CHARS) {
            $this->laravelPailBuffer = '… (older lines trimmed) …'."\n".substr($this->laravelPailBuffer, -self::PAIL_BUFFER_MAX_CHARS);
        }

        $this->laravelPailOffset = $newOffset;
    }

    /**
     * Operator-toggled live mode (wire:poll firing every 2s in the view).
     */
    public function toggleLaravelPailLive(): void
    {
        $this->laravelPailLive = ! $this->laravelPailLive;
    }

    /**
     * Manual reset — clears the buffer and re-baselines offset so
     * Refresh fetches the last 200 lines again instead of incremental.
     */
    public function resetLaravelPail(): void
    {
        $this->laravelPailBuffer = '';
        $this->laravelPailOffset = 0;
        $this->laravelPailLoaded = false;
    }

    private function laravelLogPath(): string
    {
        $deployPath = $this->site->document_root ?: $this->site->repository_path ?: '/home/dply/'.$this->site->slug;
        // strip "/public" suffix if document_root points at the public/ dir
        $deployBase = preg_replace('#/public/?$#', '', $deployPath);

        return rtrim((string) $deployBase, '/').'/storage/logs/laravel.log';
    }

    public function saveLaravelCustomCommands(LaravelConsoleExecutor $executor): void
    {
        $this->authorize('update', $this->site);

        if (auth()->user()->currentOrganization()?->userIsDeployer(auth()->user())) {
            $this->toastError(__('Deployers cannot edit custom Artisan commands.'));

            return;
        }

        $lines = preg_split('/\R/', $this->laravel_custom_commands_text) ?: [];
        $clean = [];
        foreach ($lines as $line) {
            $t = trim((string) $line);
            if ($t === '') {
                continue;
            }
            $executor->assertSafeArtisanArgv($t);
            $clean[] = $t;
        }

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $lc = is_array($meta['laravel_console'] ?? null) ? $meta['laravel_console'] : [];
        $lc['custom_commands'] = $clean;
        $meta['laravel_console'] = $lc;
        $this->site->update(['meta' => $meta]);
        $this->site->refresh();
        $this->syncLaravelConsoleForm();
        $this->toastSuccess(__('Custom Artisan commands saved.'));
    }

    public function runLaravelArtisanPreset(string $argvTail, LaravelConsoleExecutor $executor): void
    {
        $this->authorize('update', $this->site);

        if (auth()->user()->currentOrganization()?->userIsDeployer(auth()->user())) {
            $this->laravel_console_error = __('Deployers cannot run Artisan commands on servers.');
            $this->resetRemoteSshStreamTargets();

            return;
        }

        $this->laravel_console_error = null;
        $timeout = 600;

        try {
            $this->resetRemoteSshStreamTargets();
            $this->remoteSshStreamSetMeta(
                __('Artisan'),
                'php artisan '.trim($argvTail)
            );
            $executor->runArtisan(
                $this->site->fresh(),
                $argvTail,
                $timeout,
                fn (string $chunk) => $this->remoteSshStreamAppendStdout($chunk)
            );
        } catch (\Throwable $e) {
            $this->laravel_console_error = $e->getMessage();
        }
    }

    public function runLaravelApplicationLogTail(LaravelConsoleExecutor $executor): void
    {
        $this->authorize('update', $this->site);

        if (auth()->user()->currentOrganization()?->userIsDeployer(auth()->user())) {
            $this->laravel_console_error = __('Deployers cannot tail Laravel logs.');
            $this->resetRemoteSshStreamTargets();

            return;
        }

        $this->validate([
            'laravel_log_tail_lines' => 'required|integer|min:50|max:5000',
        ]);

        $this->laravel_console_error = null;

        try {
            $this->resetRemoteSshStreamTargets();
            $this->remoteSshStreamSetMeta(
                __('Laravel log'),
                'tail -n '.(int) $this->laravel_log_tail_lines.' storage/logs/laravel.log'
            );
            $executor->tailLaravelLog(
                $this->site->fresh(),
                (int) $this->laravel_log_tail_lines,
                fn (string $chunk) => $this->remoteSshStreamAppendStdout($chunk)
            );
        } catch (\Throwable $e) {
            $this->laravel_console_error = $e->getMessage();
        }
    }

    public function saveLaravelOctaneTab(): void
    {
        $this->authorize('update', $this->site);

        if ($this->server->hostCapabilities()->supportsFunctionDeploy()) {
            $this->toastError(__('Octane settings apply to VM and container sites.'));

            return;
        }

        if (! $this->site->shouldShowPhpOctaneRolloutSettings() || ! $this->site->shouldShowOctaneRuntimeUi()) {
            return;
        }

        $this->validate([
            'octane_port' => 'nullable|integer|min:1|max:65535',
            'octane_server' => ['required', Rule::in(Site::OCTANE_SERVERS)],
        ]);

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $lo = is_array($meta['laravel_octane'] ?? null) ? $meta['laravel_octane'] : [];
        $lo['server'] = $this->octane_server;
        $meta['laravel_octane'] = $lo;

        $this->site->update([
            'octane_port' => $this->octane_port !== '' ? (int) $this->octane_port : null,
            'meta' => $meta,
        ]);
        $this->site->refresh();
        $this->syncFormFromSite();
        $this->toastSuccess(__('Octane settings saved.'));
    }

    public function saveLaravelReverbTab(): void
    {
        $this->authorize('update', $this->site);

        if ($this->server->hostCapabilities()->supportsFunctionDeploy()) {
            $this->toastError(__('Reverb settings apply to VM and container sites.'));

            return;
        }

        if (! $this->site->shouldShowPhpOctaneRolloutSettings()) {
            return;
        }

        if (! $this->site->shouldShowLaravelReverbRuntimeUi() && ! $this->site->shouldProxyReverbInWebserver()) {
            return;
        }

        $this->validate([
            'laravel_reverb_port' => 'nullable|integer|min:1|max:65535',
            'laravel_reverb_ws_path' => ['nullable', 'string', 'max:128'],
        ]);

        $meta = is_array($this->site->meta) ? $this->site->meta : [];
        $rv = is_array($meta['laravel_reverb'] ?? null) ? $meta['laravel_reverb'] : [];
        $rv['port'] = $this->laravel_reverb_port !== '' ? (int) $this->laravel_reverb_port : 8080;
        $ws = trim($this->laravel_reverb_ws_path);
        $rv['ws_path'] = $ws !== '' ? $ws : '/app';
        $meta['laravel_reverb'] = $rv;

        $this->site->update(['meta' => $meta]);
        $this->site->refresh();
        $this->syncFormFromSite();
        $this->toastSuccess(__('Reverb settings saved.'));
    }

    public function saveLaravelSetupTab(): void
    {
        $this->authorize('update', $this->site);

        if ($this->server->hostCapabilities()->supportsFunctionDeploy()) {
            $this->toastError(__('These settings apply to VM and container sites.'));

            return;
        }

        if (! $this->site->shouldShowPhpOctaneRolloutSettings()) {
            return;
        }

        $this->validate([
            'laravel_horizon_path' => ['nullable', 'string', 'max:128'],
            'laravel_horizon_notes' => ['nullable', 'string', 'max:2000'],
            'laravel_pulse_path' => ['nullable', 'string', 'max:128'],
            'laravel_pulse_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $meta = is_array($this->site->meta) ? $this->site->meta : [];

        if ($this->site->resolvedLaravelPackageFlag('horizon')) {
            $meta['laravel_horizon'] = [
                'path' => trim($this->laravel_horizon_path) !== '' ? trim($this->laravel_horizon_path) : '/horizon',
                'notes' => trim($this->laravel_horizon_notes),
            ];
        }

        if ($this->site->resolvedLaravelPackageFlag('pulse')) {
            $meta['laravel_pulse'] = [
                'path' => trim($this->laravel_pulse_path) !== '' ? trim($this->laravel_pulse_path) : '/pulse',
                'notes' => trim($this->laravel_pulse_notes),
            ];
        }

        $this->site->update(['meta' => $meta]);
        $this->site->refresh();
        $this->syncFormFromSite();
        $this->toastSuccess(__('Laravel setup notes saved.'));
    }

    public function saveLaravelStackSettings(): void
    {
        $this->authorize('update', $this->site);

        if ($this->server->hostCapabilities()->supportsFunctionDeploy()) {
            $this->toastError(__('Laravel stack settings apply to VM and container sites that use SSH deploy and managed web server config.'));

            return;
        }

        if (! $this->site->shouldShowPhpOctaneRolloutSettings()) {
            return;
        }

        $this->validate([
            'laravel_reverb_port' => 'nullable|integer|min:1|max:65535',
            'laravel_reverb_ws_path' => ['nullable', 'string', 'max:128'],
            'laravel_horizon_path' => ['nullable', 'string', 'max:128'],
            'laravel_horizon_notes' => ['nullable', 'string', 'max:2000'],
            'laravel_pulse_path' => ['nullable', 'string', 'max:128'],
            'laravel_pulse_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $meta = is_array($this->site->meta) ? $this->site->meta : [];

        if ($this->site->shouldShowLaravelReverbRuntimeUi() || $this->site->shouldProxyReverbInWebserver()) {
            $rv = is_array($meta['laravel_reverb'] ?? null) ? $meta['laravel_reverb'] : [];
            $rv['port'] = $this->laravel_reverb_port !== '' ? (int) $this->laravel_reverb_port : 8080;
            $ws = trim($this->laravel_reverb_ws_path);
            $rv['ws_path'] = $ws !== '' ? $ws : '/app';
            $meta['laravel_reverb'] = $rv;
        }

        if ($this->site->resolvedLaravelPackageFlag('horizon')) {
            $meta['laravel_horizon'] = [
                'path' => trim($this->laravel_horizon_path) !== '' ? trim($this->laravel_horizon_path) : '/horizon',
                'notes' => trim($this->laravel_horizon_notes),
            ];
        }

        if ($this->site->resolvedLaravelPackageFlag('pulse')) {
            $meta['laravel_pulse'] = [
                'path' => trim($this->laravel_pulse_path) !== '' ? trim($this->laravel_pulse_path) : '/pulse',
                'notes' => trim($this->laravel_pulse_notes),
            ];
        }

        $this->site->update(['meta' => $meta]);
        $this->syncFormFromSite();
        $this->finalizeRoutingMutation(__('Laravel stack settings saved.'));
    }

    public function openLaravelSshSetupModal(string $action, LaravelSiteSshSetupRunner $runner): void
    {
        $this->authorize('update', $this->site);
        $this->laravel_ssh_setup_error = null;

        try {
            $runner->assertActionAllowed($this->site, $action);
        } catch (\InvalidArgumentException $e) {
            $this->laravel_ssh_setup_error = $e->getMessage();

            return;
        }

        $this->laravel_ssh_setup_pending_action = $action;
        $this->dispatch('open-modal', 'laravel-ssh-setup-modal');
    }

    public function closeLaravelSshSetupModal(): void
    {
        $this->laravel_ssh_setup_pending_action = null;
        $this->dispatch('close-modal', 'laravel-ssh-setup-modal');
    }

    public function confirmLaravelSshSetup(LaravelSiteSshSetupRunner $runner, SiteScopedCommandWrapper $commandWrapper): void
    {
        $this->authorize('update', $this->site);

        if (auth()->user()->currentOrganization()?->userIsDeployer(auth()->user())) {
            $this->laravel_ssh_setup_error = __('Deployers cannot run remote setup commands on servers.');
            $this->closeLaravelSshSetupModal();

            return;
        }

        $action = $this->laravel_ssh_setup_pending_action;
        if ($action === null) {
            return;
        }

        $this->laravel_ssh_setup_error = null;

        try {
            $runner->assertActionAllowed($this->site, $action);
            $rawCmd = $runner->commandFor($this->site, $action);
            $cmd = $commandWrapper->wrapRemoteExec($this->site, $rawCmd);
            $timeout = $runner->timeoutSecondsFor($action);
            $this->resetRemoteSshStreamTargets();
            $server = $this->site->server;
            if ($server === null) {
                throw new \RuntimeException(__('Server is not available.'));
            }
            $this->remoteSshStreamSetMeta(
                __('Laravel setup'),
                $commandWrapper->executionSummary($this->site).' @ '.$server->ip_address.'  '.$cmd
            );
            $ssh = new SshConnection($server);
            $ssh->execWithCallback(
                $cmd,
                fn (string $chunk) => $this->remoteSshStreamAppendStdout($chunk),
                $timeout
            );
            $exit = $ssh->lastExecExitCode();
            if ($exit !== null && $exit !== 0) {
                $this->laravel_ssh_setup_error = __('Command exited with code :code.', ['code' => $exit]);
            } else {
                $this->toastSuccess(__('Setup command finished.'));
            }
        } catch (\Throwable $e) {
            $this->laravel_ssh_setup_error = $e->getMessage();
        }

        $this->laravel_ssh_setup_pending_action = null;
        $this->dispatch('close-modal', 'laravel-ssh-setup-modal');
    }

    public function laravelSshSetupPendingCommandPreview(): ?string
    {
        if ($this->laravel_ssh_setup_pending_action === null) {
            return null;
        }

        $runner = app(LaravelSiteSshSetupRunner::class);

        try {
            return $runner->commandFor($this->site, $this->laravel_ssh_setup_pending_action);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
