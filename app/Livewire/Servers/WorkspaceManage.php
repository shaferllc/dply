<?php

namespace App\Livewire\Servers;

use App\Jobs\AddEdgeProxyJob;
use App\Jobs\RemoveEdgeProxyJob;
use App\Jobs\RevertServerWebserverSwitchJob;
use App\Jobs\ServerManageRemoteSshJob;
use App\Jobs\SwitchServerWebserverJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DismissesConsoleActionRun;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\RunsServerInventoryProbe;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\ServerManageAction;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\Servers\ServerManageSshExecutor;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Services\Servers\WebserverSwitchPreflight;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceManage extends Component
{
    use ConfirmsActionWithModal;
    use DismissesConsoleActionRun;
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;
    use RunsServerInventoryProbe;

    /** @var string Manage sub-page slug (see config server_manage.workspace_tabs). */
    public string $section = 'overview';

    public string $manage_db_bind_host = '';

    public ?int $manage_db_port = null;

    public string $manage_db_password = '';

    public string $manage_auto_updates_interval = 'off';

    /**
     * Cascade preview for a pending webserver switch — set by openSwitchWebserver()
     * when the operator clicks "Switch to <target>" on the web tab. Consumed by
     * the confirmation modal in group-web.blade.php. Null when no switch is pending.
     * Shape matches {@see WebserverSwitchPreflight::plan()}.
     *
     * @var array<string, mixed>|null
     */
    public ?array $switch_plan = null;

    /** Opt-in: hand TLS to caddy auto-HTTPS at cutover. Greyed out for apache. */
    public bool $switch_tls_to_caddy = false;

    public ?string $remote_output = null;

    public ?string $remote_error = null;

    /**
     * When set, {@see syncManageRemoteTaskFromCache} polls cache until the queued SSH task finishes.
     */
    public ?string $manageRemoteTaskId = null;

    public function mount(Server $server, ?string $section = null): void
    {
        if ($section === null) {
            $this->redirect(route('servers.manage', ['server' => $server, 'section' => 'overview']), navigate: true);

            return;
        }

        // 'web' was promoted to its own top-level sidebar entry (servers.webserver) so
        // operators get to the picker / cascade modal / switch history without
        // drilling through Manage. Old deep links + bookmarks redirect.
        // Note: this redirect runs only when WorkspaceWebserver inherits via parent::mount();
        // since WorkspaceWebserver's mount() passes 'web' explicitly, the check below
        // is the back-compat path for direct /manage/web URLs only — by the time the
        // child class is mounted, the route has already routed to /webserver.
        if ($section === 'web' && static::class === self::class) {
            $this->redirect(route('servers.webserver', ['server' => $server]), navigate: true);

            return;
        }

        // 'services' was retired from the Manage workspace_tabs because the
        // standalone /services page is the canonical surface. Redirect deep
        // links instead of 404-ing — bookmarks and any cached external URLs
        // (digest emails, etc.) keep working.
        if ($section === 'services') {
            $this->redirect(route('servers.services', ['server' => $server]), navigate: true);

            return;
        }

        // Subclasses (currently WorkspaceWebserver) get a small section allowlist
        // extension so their inherited mount() can pass a logical section name
        // ('web') that's no longer in workspace_tabs config — the tab strip in the
        // Manage view doesn't render 'web' anymore but the inherited state still
        // needs a non-null $section for the rest of the flow.
        $allowed = array_keys(config('server_manage.workspace_tabs', []));
        if (static::class !== self::class) {
            $allowed[] = 'web';
        }
        if (! in_array($section, $allowed, true)) {
            abort(404);
        }

        $this->section = $section;

        $this->bootWorkspace($server);
        $meta = $server->meta ?? [];
        $this->manage_db_bind_host = (string) ($meta['manage_db_bind_host'] ?? '');
        $port = $meta['manage_db_port'] ?? null;
        $this->manage_db_port = is_numeric($port) ? (int) $port : null;
        $this->manage_auto_updates_interval = (string) ($meta['manage_auto_updates_interval'] ?? 'off');
    }

    public function saveManageMetadata(): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot change server manage settings.'));

            return;
        }

        $this->validate([
            'manage_db_bind_host' => ['nullable', 'string', 'max:255'],
            'manage_db_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'manage_auto_updates_interval' => ['required', 'string', 'in:'.implode(',', array_keys(config('server_manage.auto_update_intervals', [])))],
        ]);

        $meta = $this->server->meta ?? [];
        $meta['manage_db_bind_host'] = $this->manage_db_bind_host !== '' ? $this->manage_db_bind_host : null;
        $meta['manage_db_port'] = $this->manage_db_port;
        $meta['manage_auto_updates_interval'] = $this->manage_auto_updates_interval;

        if ($this->manage_db_password !== '') {
            $meta['manage_internal_db_password'] = $this->manage_db_password;
        }

        $this->server->update(['meta' => $meta]);
        $this->manage_db_password = '';
        $this->server->refresh();
        $this->toastSuccess(__('Manage preferences saved.'));
    }

    public function previewConfig(string $key): void
    {
        $previews = config('server_manage.config_previews', []);
        $entry = $previews[$key] ?? null;
        if (! is_array($entry) || empty($entry['path'])) {
            $this->remote_error = __('Unknown configuration preview.');

            return;
        }

        $this->runConfigPreview('manage-config-preview:'.$key, (string) $entry['path']);
    }

    public function previewConfigPath(string $path): void
    {
        $taskName = 'manage-config-preview-path:'.substr(sha1($path), 0, 12);
        $this->runConfigPreview($taskName, $path);
    }

    protected function runConfigPreview(string $taskName, string $path): void
    {
        $this->authorize('update', $this->server);
        $this->remote_output = null;
        $this->remote_error = null;

        if ($this->currentUserIsDeployer()) {
            $this->remote_error = __('Deployers cannot read configuration over SSH.');

            return;
        }

        try {
            $this->assertAllowlistedConfigPath($path);
        } catch (\InvalidArgumentException) {
            $this->remote_error = __('That path is not allowlisted.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->remote_error = __('SSH must be ready before previewing configuration.');

            return;
        }

        set_time_limit(120);

        $max = (int) config('server_manage.config_preview_max_bytes', 48_000);
        $pathArg = escapeshellarg($path);
        $inline = <<<BASH
path={$pathArg}
max={$max}
if [[ -r "\$path" ]]; then
  head -c "\$max" "\$path" || true
else
  echo "Not found or not readable: \$path"
fi
BASH;

        try {
            $server = $this->server->fresh();
            $title = __('TaskRunner (SSH)').' — '.__('Configuration preview').': '.$path;
            if ($this->shouldQueueManageRemoteTasks()) {
                $this->dispatchQueuedManageScript($server, $taskName, $inline, 120, null, $title);

                return;
            }

            $this->resetRemoteSshStreamTargets();
            $this->remoteSshStreamSetMeta($title, $this->manageSshConnectionLabel($server)."\n".__('Remote script').":\n".$inline);
            $out = $this->runManageInlineBash(
                $server,
                $taskName,
                $inline,
                fn (string $type, string $buffer) => $this->remoteSshStreamAppendStdout($buffer),
                120,
            );
            $this->remote_output = trim(ServerManageSshExecutor::stripSshClientNoise($out->getBuffer()));
        } catch (\Throwable $e) {
            $this->remote_error = $e->getMessage();
        }
    }

    public function cancelQueuedManageTasks(): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot cancel queued tasks.'));

            return;
        }

        $cleared = 0;
        if ($this->manageRemoteTaskId !== null && $this->manageRemoteTaskId !== '') {
            Cache::forget(ServerManageRemoteSshJob::cacheKey($this->manageRemoteTaskId));
            $cleared++;
        }
        $this->manageRemoteTaskId = null;
        $this->remote_output = null;
        $this->remote_error = null;
        $this->resetRemoteSshStreamTargets();
        $this->toastSuccess(__('Cleared :n queued task(s) from this view.', ['n' => $cleared]));
    }

    public function runAllowlistedAction(string $key): void
    {
        $this->authorize('update', $this->server);
        $this->remote_output = null;
        $this->remote_error = null;

        if ($this->currentUserIsDeployer()) {
            $this->remote_error = __('Deployers cannot run service actions on servers.');

            return;
        }

        $service = config('server_manage.service_actions', []);
        $danger = config('server_manage.dangerous_actions', []);
        $def = $service[$key] ?? $danger[$key] ?? null;
        if (! is_array($def) || empty($def['script'])) {
            $this->remote_error = __('Unknown action.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->remote_error = __('Provisioning and SSH must be ready before running actions.');

            return;
        }

        set_time_limit((int) ($def['timeout'] ?? 120) + 30);

        $script = (string) $def['script'];
        $meta = $this->server->meta ?? [];
        if (in_array($key, ['restart_php_fpm', 'reload_php_fpm'], true)) {
            $v = (string) ($meta['default_php_version'] ?? '8.3');
            if (! preg_match('/^\d+\.\d+$/', $v)) {
                $v = '8.3';
            }
            $script = 'export DPLY_PHP_VERSION='.escapeshellarg($v)."\n".$script;
        }
        if (str_starts_with($key, 'mysql_') && ! empty($meta['manage_internal_db_password']) && is_string($meta['manage_internal_db_password'])) {
            $script = 'export DPLY_DB_PASSWORD='.escapeshellarg($meta['manage_internal_db_password'])."\n".$script;
        }

        try {
            $server = $this->server->fresh();
            $timeout = isset($def['timeout']) ? (int) $def['timeout'] : null;
            $flash = ($def['label'] ?? $key).' '.__('finished.');
            $label = (string) ($def['label'] ?? $key);

            if ($this->shouldQueueManageRemoteTasks()) {
                $this->dispatchQueuedManageScript(
                    $server,
                    'manage-action:'.$key,
                    $script,
                    $timeout,
                    $flash,
                    __('TaskRunner (SSH)').' — '.$label,
                    $label,
                );

                return;
            }

            // Sync path — seed the ConsoleAction row so the banner picks it up
            // in real time, then stream output lines into it as they arrive
            // alongside the existing remote_output buffer.
            $consoleId = $this->seedManageConsoleAction($server, $label);
            $emitter = new \App\Services\ConsoleActions\ConsoleEmitter($consoleId);
            \Illuminate\Support\Facades\DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => \App\Models\ConsoleAction::STATUS_RUNNING,
                'started_at' => now(),
                'updated_at' => now(),
            ]);

            $this->resetRemoteSshStreamTargets();
            $this->remoteSshStreamSetMeta(
                __('TaskRunner (SSH)').' — '.$label,
                $this->manageSshConnectionLabel($server)."\n".__('Remote script').":\n".$script
            );
            try {
                $out = $this->runManageInlineBash(
                    $server,
                    'manage-action:'.$key,
                    $script,
                    function (string $type, string $buffer) use ($emitter): void {
                        $this->remoteSshStreamAppendStdout($buffer);
                        foreach (preg_split('/\R/', rtrim($buffer, "\n")) ?: [] as $line) {
                            if ($line !== '') {
                                $emitter($line);
                            }
                        }
                    },
                    $timeout,
                );
                $this->remote_output = trim(ServerManageSshExecutor::stripSshClientNoise($out->getBuffer()));
                \Illuminate\Support\Facades\DB::table('console_actions')->where('id', $consoleId)->update([
                    'status' => \App\Models\ConsoleAction::STATUS_COMPLETED,
                    'finished_at' => now(),
                    'error' => null,
                    'updated_at' => now(),
                ]);
                $this->toastSuccess($flash);
            } catch (\Throwable $inner) {
                \Illuminate\Support\Facades\DB::table('console_actions')->where('id', $consoleId)->update([
                    'status' => \App\Models\ConsoleAction::STATUS_FAILED,
                    'finished_at' => now(),
                    'error' => mb_substr($inner->getMessage(), 0, 2000),
                    'updated_at' => now(),
                ]);
                throw $inner;
            }
        } catch (\Throwable $e) {
            $this->remote_error = $e->getMessage();
        }
    }

    /**
     * Open the webserver-switch cascade modal. Computes the preflight server-side
     * (PHP-compat hard block, drift warnings, computed downtime breakdown, opt-in
     * TLS cascade) and stashes the result on the component for the modal to render.
     *
     * Refuses to open if there's already an in-flight switch ConsoleAction —
     * the live progress banner is the canonical UI while a switch is running.
     */
    public function openSwitchWebserver(string $target): void
    {
        $this->authorize('update', $this->server);

        if ($this->hasInflightWebserverSwitch()) {
            $this->toastError(__('A webserver switch is already in flight — wait for it to finish before starting another.'));

            return;
        }

        $target = strtolower(trim($target));
        if (! in_array($target, WebserverSwitchPreflight::KNOWN_WEBSERVERS, true)) {
            $this->toastError(__('Unknown webserver target: :t.', ['t' => $target]));

            return;
        }

        $this->switch_plan = app(WebserverSwitchPreflight::class)->plan($this->server, $target);
        $this->switch_tls_to_caddy = false;
        $this->dispatch('open-modal', 'webserver-switch-modal');
    }

    /**
     * Dispatch the SwitchServerWebserverJob with the operator's opt-in selections.
     * The job seeds its own ConsoleAction row inside handle(), and the banner
     * picks it up from there. We just need to fire and forget; UI updates via
     * the banner poll.
     */
    public function confirmSwitchWebserver(): void
    {
        $this->authorize('update', $this->server);

        if ($this->switch_plan === null) {
            return;
        }
        if (($this->switch_plan['blocker'] ?? null) !== null) {
            // Modal shouldn't have allowed confirm with a blocker, but be defensive.
            $this->toastError(__('Cannot switch: :reason', ['reason' => $this->switch_plan['blocker']['label']]));

            return;
        }
        if ($this->hasInflightWebserverSwitch()) {
            $this->toastError(__('A webserver switch is already in flight.'));

            return;
        }

        $from = (string) ($this->switch_plan['from'] ?? '—');
        $target = (string) $this->switch_plan['to'];

        // Seed a queued ConsoleAction row BEFORE dispatch so the banner shows
        // immediately — without this the row only gets created when the worker
        // picks the job up, leaving operators staring at a button that "did
        // nothing." Mirrors the seedQueuedConsoleAction pattern from Sites\Show
        // for ApplySiteWebserverConfigJob.
        $this->seedQueuedWebserverSwitchAction(
            label: __('Switching webserver: :from → :to …', ['from' => $from, 'to' => $target]),
            from: $from,
            to: $target,
        );

        SwitchServerWebserverJob::dispatch(
            serverId: $this->server->id,
            target: $target,
            tlsToCaddy: $this->switch_tls_to_caddy,
            userId: auth()->id(),
        );

        $this->switch_plan = null;
        $this->switch_tls_to_caddy = false;
        $this->dispatch('close-modal', 'webserver-switch-modal');
        $this->toastSuccess(__('Webserver switch queued. Progress shows in the banner above.'));
    }

    /**
     * Seed a queued `ConsoleAction` row for the upcoming `webserver_switch` job
     * so the banner-static partial picks it up on the next render — without
     * waiting for the worker to claim the job. Auto-dismisses prior terminal +
     * stale-running rows so the operator sees only the run they just started.
     * Mirrors {@see \App\Livewire\Sites\Show::seedQueuedConsoleAction()} but
     * scoped to a Server subject instead of a Site.
     *
     * `from`/`to` are persisted in `output['meta']` so {@see stopAndRevertWebserverSwitch()}
     * can recover them without label parsing if the operator aborts a stuck
     * switch later. The banner's {@see ConsoleAction::lines()} reader ignores
     * non-`lines` keys, so this extra metadata is safe to carry alongside.
     */
    protected function seedQueuedWebserverSwitchAction(
        ?string $label = null,
        ?string $from = null,
        ?string $to = null,
    ): ConsoleAction {
        $subjectType = $this->server->getMorphClass();
        $subjectId = $this->server->id;

        ConsoleAction::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->whereNull('dismissed_at')
            ->whereIn('status', [ConsoleAction::STATUS_COMPLETED, ConsoleAction::STATUS_FAILED])
            ->update(['dismissed_at' => now()]);

        $staleSeconds = (int) config('console_actions.stale_after_seconds', 600);
        ConsoleAction::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->whereNull('dismissed_at')
            ->whereIn('status', [ConsoleAction::STATUS_QUEUED, ConsoleAction::STATUS_RUNNING])
            ->where('created_at', '<', now()->subSeconds($staleSeconds))
            ->update(['dismissed_at' => now()]);

        $output = ['v' => (int) config('console_actions.current_version', 1), 'lines' => []];
        if ($from !== null || $to !== null) {
            $output['meta'] = array_filter([
                'from' => $from,
                'to' => $to,
            ], static fn ($v) => $v !== null);
        }

        return ConsoleAction::query()->create([
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'kind' => 'webserver_switch',
            'status' => ConsoleAction::STATUS_QUEUED,
            'label' => $label,
            'user_id' => request()->user()?->id,
            'output' => $output,
        ]);
    }

    /**
     * Discard the pending switch — closes the modal, leaves the server untouched.
     */
    public function cancelSwitchWebserver(): void
    {
        $this->switch_plan = null;
        $this->switch_tls_to_caddy = false;
        $this->dispatch('close-modal', 'webserver-switch-modal');
    }

    /**
     * Operator escape hatch for a stuck switch: marks the in-flight (or stale)
     * webserver_switch ConsoleAction as failed + dismissed and dispatches a
     * {@see RevertServerWebserverSwitchJob} that best-effort uninstalls the
     * partial target and brings the original webserver back on :80.
     *
     * Triggered from the "Stop & revert" button rendered alongside the banner
     * when {@see hasInflightWebserverSwitch()} is true. The from/to pair comes
     * from the seeded ConsoleAction's `output['meta']` (set by
     * {@see seedQueuedWebserverSwitchAction()}); we fall back to label parsing
     * for older rows that predate that field.
     */
    public function stopAndRevertWebserverSwitch(string $runId): void
    {
        $this->authorize('update', $this->server);

        $row = ConsoleAction::query()
            ->where('id', $runId)
            ->where('subject_type', $this->server->getMorphClass())
            ->where('subject_id', $this->server->getKey())
            ->where('kind', 'webserver_switch')
            ->whereNull('dismissed_at')
            ->first();

        if ($row === null || ! $row->isInFlight()) {
            $this->toastError(__('No in-flight webserver switch to revert.'));

            return;
        }

        $output = is_array($row->output) ? $row->output : [];
        $meta = is_array($output['meta'] ?? null) ? $output['meta'] : [];
        $serverWebserver = strtolower((string) ($this->server->meta['webserver'] ?? 'nginx'));
        $from = strtolower((string) ($meta['from'] ?? $serverWebserver));
        $to = strtolower((string) ($meta['to'] ?? ''));

        if ($to === '' && is_string($row->label) && preg_match('/→\s*(\S+)/u', $row->label, $m)) {
            $to = strtolower((string) $m[1]);
        }

        if ($to === '' || $to === $from) {
            $this->toastError(__('Cannot determine the revert target from the failed switch.'));

            return;
        }

        // Mark the stuck row failed + dismissed so hasInflightWebserverSwitch()
        // releases and the seed below isn't auto-dismissed by its own pre-clean.
        $row->forceFill([
            'status' => ConsoleAction::STATUS_FAILED,
            'finished_at' => now(),
            'error' => 'Aborted by operator',
            'dismissed_at' => now(),
        ])->save();

        // Seed a fresh row so the banner immediately switches to the revert
        // progress view rather than going blank between dispatch and worker
        // pickup. Same kind so the banner partial transparently picks it up.
        $this->seedQueuedWebserverSwitchAction(
            label: __('Reverting webserver switch: :to → :from …', ['to' => $to, 'from' => $from]),
            from: $to,
            to: $from,
        );

        RevertServerWebserverSwitchJob::dispatch(
            serverId: $this->server->id,
            target: $to,
            from: $from,
            userId: auth()->id(),
        );

        $this->toastSuccess(__('Stopping the switch and reverting :to → :from. Progress shows in the banner.', [
            'to' => $to,
            'from' => $from,
        ]));
    }

    /**
     * Required by {@see DismissesConsoleActionRun}: identifies which model the
     * banner is scoped to. WorkspaceManage's banner shows server-level runs
     * (webserver_switch, etc.), so the subject is the server.
     */
    protected function consoleActionSubject(): \Illuminate\Database\Eloquent\Model
    {
        return $this->server;
    }

    /**
     * True when there's a queued/running webserver_switch ConsoleAction for this
     * server. Used to disable the switch CTAs and short-circuit re-entry.
     */
    public function hasInflightWebserverSwitch(): bool
    {
        return ConsoleAction::query()
            ->where('subject_type', $this->server->getMorphClass())
            ->where('subject_id', $this->server->getKey())
            ->where('kind', 'webserver_switch')
            ->whereIn('status', [ConsoleAction::STATUS_QUEUED, ConsoleAction::STATUS_RUNNING])
            ->whereNull('dismissed_at')
            ->exists();
    }

    /**
     * Inflight check for edge-proxy add/remove. Same shape as
     * {@see hasInflightWebserverSwitch()} but scoped to the `edge_proxy`
     * console-action kind so the two banners don't shadow each other.
     */
    public function hasInflightEdgeProxyAction(): bool
    {
        return ConsoleAction::query()
            ->where('subject_type', $this->server->getMorphClass())
            ->where('subject_id', $this->server->getKey())
            ->where('kind', 'edge_proxy')
            ->whereIn('status', [ConsoleAction::STATUS_QUEUED, ConsoleAction::STATUS_RUNNING])
            ->whereNull('dismissed_at')
            ->exists();
    }

    /**
     * Dispatch {@see AddEdgeProxyJob}. Seeds a queued ConsoleAction so the
     * banner shows immediately rather than blanking until the worker picks
     * the job up.
     */
    public function addEdgeProxy(string $target): void
    {
        $this->authorize('update', $this->server);

        $target = strtolower(trim($target));
        if (! in_array($target, ['traefik', 'haproxy'], true)) {
            $this->toastError(__('Unknown edge proxy: :t.', ['t' => $target]));

            return;
        }
        if ($this->hasInflightEdgeProxyAction() || $this->hasInflightWebserverSwitch()) {
            $this->toastError(__('Another webserver action is in flight — wait for it to finish.'));

            return;
        }

        $this->seedQueuedEdgeProxyAction(
            label: __('Adding edge proxy: :target …', ['target' => $target]),
            meta: ['op' => 'add', 'target' => $target],
        );

        AddEdgeProxyJob::dispatch(
            serverId: $this->server->id,
            target: $target,
            userId: auth()->id(),
        );

        $this->toastSuccess(__('Edge proxy queued. Progress shows in the banner above.'));
    }

    /**
     * Dispatch {@see RemoveEdgeProxyJob}. Caddy takes over :80 again once
     * the job lands; meta.webserver lands as 'caddy' since that's what's
     * actually serving content post-remove.
     */
    public function removeEdgeProxy(): void
    {
        $this->authorize('update', $this->server);

        $edge = $this->server->edgeProxy();
        if ($edge === null) {
            $this->toastError(__('No edge proxy is active on this server.'));

            return;
        }
        if ($this->hasInflightEdgeProxyAction() || $this->hasInflightWebserverSwitch()) {
            $this->toastError(__('Another webserver action is in flight — wait for it to finish.'));

            return;
        }

        $this->seedQueuedEdgeProxyAction(
            label: __('Removing edge proxy: :target …', ['target' => $edge]),
            meta: ['op' => 'remove', 'target' => $edge],
        );

        RemoveEdgeProxyJob::dispatch(
            serverId: $this->server->id,
            userId: auth()->id(),
        );

        $this->toastSuccess(__('Edge proxy removal queued. Progress shows in the banner above.'));
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function seedQueuedEdgeProxyAction(?string $label, array $meta = []): ConsoleAction
    {
        $subjectType = $this->server->getMorphClass();
        $subjectId = $this->server->id;

        ConsoleAction::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->whereNull('dismissed_at')
            ->whereIn('status', [ConsoleAction::STATUS_COMPLETED, ConsoleAction::STATUS_FAILED])
            ->update(['dismissed_at' => now()]);

        $staleSeconds = (int) config('console_actions.stale_after_seconds', 600);
        ConsoleAction::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->whereNull('dismissed_at')
            ->whereIn('status', [ConsoleAction::STATUS_QUEUED, ConsoleAction::STATUS_RUNNING])
            ->where('created_at', '<', now()->subSeconds($staleSeconds))
            ->update(['dismissed_at' => now()]);

        $output = [
            'v' => (int) config('console_actions.current_version', 1),
            'lines' => [],
            'meta' => $meta,
        ];

        return ConsoleAction::query()->create([
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'kind' => 'edge_proxy',
            'status' => ConsoleAction::STATUS_QUEUED,
            'label' => $label,
            'user_id' => request()->user()?->id,
            'output' => $output,
        ]);
    }

    public function syncManageRemoteTaskFromCache(): void
    {
        if ($this->manageRemoteTaskId === null || $this->manageRemoteTaskId === '') {
            return;
        }

        $payload = Cache::get(ServerManageRemoteSshJob::cacheKey($this->manageRemoteTaskId));
        if (! is_array($payload)) {
            return;
        }

        $status = (string) ($payload['status'] ?? '');
        $out = (string) ($payload['output'] ?? '');
        $queuedAt = isset($payload['queued_at']) && is_numeric($payload['queued_at'])
            ? (int) $payload['queued_at']
            : null;
        $stalledAfter = (int) config('server_manage.remote_task_stalled_queued_seconds', 45);
        $stalledQueued = $status === 'queued'
            && $queuedAt !== null
            && (time() - $queuedAt) > $stalledAfter;

        $this->remote_output = $out !== ''
            ? $out
            : match ($status) {
                'queued' => $stalledQueued
                    ? __('Still preparing this task. If it stays stuck, contact your administrator.')
                    : __('Task queued…'),
                'running' => __('Running on server…'),
                default => '',
            };

        $err = $payload['error'] ?? null;
        $this->remote_error = is_string($err) && $err !== '' ? $err : null;

        if (! in_array($status, ['finished', 'failed'], true)) {
            return;
        }

        if ($status === 'finished') {
            $flash = $payload['flash_success'] ?? null;
            if (is_string($flash) && $flash !== '') {
                $this->toastSuccess($flash);
            }
        } else {
        }

        Cache::forget(ServerManageRemoteSshJob::cacheKey($this->manageRemoteTaskId));
        $this->manageRemoteTaskId = null;
    }

    public function render(): View
    {
        $this->server->refresh();

        $recentActions = $this->section === 'overview'
            ? ServerManageAction::query()
                ->where('server_id', $this->server->id)
                ->orderByDesc('created_at')
                ->limit(5)
                ->get()
            : collect();

        return view('livewire.servers.workspace-manage', [
            'configPreviews' => config('server_manage.config_previews', []),
            'serviceActions' => config('server_manage.service_actions', []),
            'dangerousActions' => config('server_manage.dangerous_actions', []),
            'autoUpdateIntervals' => config('server_manage.auto_update_intervals', []),
            'recentActions' => $recentActions,
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
        ]);
    }

    protected function assertAllowlistedConfigPath(string $path): void
    {
        $normalized = str_starts_with($path, '/') ? $path : '/'.$path;
        if (str_contains($normalized, '..')) {
            throw new \InvalidArgumentException;
        }

        foreach (config('server_manage.allowed_config_paths_exact', []) as $exact) {
            if ($normalized === $exact) {
                return;
            }
        }

        foreach (config('server_manage.allowed_config_path_prefixes', []) as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return;
            }
        }

        throw new \InvalidArgumentException;
    }

    /**
     * @param  callable(string, string):void  $onOutput
     */
    protected function runManageInlineBash(
        Server $server,
        string $taskName,
        string $inlineBash,
        callable $onOutput,
        ?int $timeoutSeconds,
    ): ProcessOutput {
        return app(ServerManageSshExecutor::class)->runInlineBash(
            $server,
            $taskName,
            $inlineBash,
            $timeoutSeconds,
            $onOutput,
        );
    }

    protected function shouldQueueManageRemoteTasks(): bool
    {
        return (bool) config('server_manage.queue_remote_tasks', true);
    }

    protected function dispatchQueuedManageScript(
        Server $server,
        string $taskName,
        string $inlineBash,
        ?int $timeoutSeconds,
        ?string $flashSuccess,
        string $streamTitle,
        ?string $consoleLabel = null,
    ): void {
        $this->manageRemoteTaskId = null;

        $id = (string) Str::uuid();
        $ttl = (int) config('server_manage.remote_task_cache_ttl_seconds', 900);

        Cache::put(ServerManageRemoteSshJob::cacheKey($id), [
            'status' => 'queued',
            'output' => '',
            'error' => null,
            'flash_success' => null,
            'queued_at' => time(),
        ], now()->addSeconds(max(120, $ttl)));

        if (config('server_manage.supersede_duplicate_remote_tasks', true)) {
            Cache::put(
                ServerManageRemoteSshJob::activeRequestCacheKey($server->id, $taskName),
                $id,
                now()->addSeconds(max(120, $ttl)),
            );
        }

        // Persist a recent-activity row so Overview can show what's happened.
        $logId = $this->logManageActionStart($server, $taskName, $streamTitle);

        // Seed a `manage_action` ConsoleAction row so workspaces that opt in to
        // the streaming banner partial (currently: Webserver) show progress in
        // the same banner-and-View-output UI the switch job uses. Other
        // workspaces ignore the row and continue rendering the legacy
        // "Command output" panel — no UX regression there.
        $consoleId = $this->seedManageConsoleAction($server, $consoleLabel ?? $streamTitle);

        ServerManageRemoteSshJob::dispatch(
            $server->id,
            $id,
            $taskName,
            $inlineBash,
            $timeoutSeconds ?? (int) config('task-runner.default_timeout', 60),
            $flashSuccess,
            $logId,
            null,
            $consoleId,
        );

        $this->manageRemoteTaskId = $id;
        $this->remote_output = __('Task queued. This page will update when the server responds.');
        $this->remote_error = null;
        $this->resetRemoteSshStreamTargets();
        $this->remoteSshStreamSetMeta(
            $streamTitle,
            $this->manageSshConnectionLabel($server)."\n".__('Remote script').":\n".$inlineBash."\n\n"
            .__('Runs in the background so the browser does not block on SSH.')
        );
    }

    /**
     * Create a `manage_action` ConsoleAction row scoped to the given server so
     * the streaming banner partial can render it. Auto-dismisses any prior
     * terminal manage_action rows for this subject so the banner-getter (which
     * picks the most recent non-dismissed run) doesn't get stuck showing an
     * old completed action.
     *
     * Scoping dismissal to `kind = manage_action` is deliberate — the
     * webserver_switch banner has its own lifecycle and we don't want one
     * manage button press to wipe out a "switch failed" surface.
     */
    protected function seedManageConsoleAction(Server $server, string $label): string
    {
        \App\Models\ConsoleAction::query()
            ->where('subject_type', $server->getMorphClass())
            ->where('subject_id', $server->id)
            ->where('kind', 'manage_action')
            ->whereNull('dismissed_at')
            ->whereIn('status', [
                \App\Models\ConsoleAction::STATUS_COMPLETED,
                \App\Models\ConsoleAction::STATUS_FAILED,
            ])
            ->update(['dismissed_at' => now()]);

        $row = \App\Models\ConsoleAction::query()->create([
            'subject_type' => $server->getMorphClass(),
            'subject_id' => $server->id,
            'kind' => 'manage_action',
            'status' => \App\Models\ConsoleAction::STATUS_QUEUED,
            'user_id' => auth()->id(),
            'label' => $label.' …',
            'output' => ['v' => (int) config('console_actions.current_version', 1), 'lines' => []],
        ]);

        return (string) $row->id;
    }

    protected function manageSshConnectionLabel(Server $server): string
    {
        $host = (string) $server->ip_address;
        $deploy = trim((string) $server->ssh_user) ?: 'root';
        if (! (bool) config('server_manage.use_root_ssh', true)) {
            return $deploy.'@'.$host;
        }

        if ($deploy === 'root') {
            return 'root@'.$host;
        }

        return 'root@'.$host.' ('.__('falls back to').' '.$deploy.'@'.$host.')';
    }

    /** Manage tabs always want the extended snapshot regardless of the user's inventory depth setting. */
    protected function forceExtendedInventoryProbe(): bool
    {
        return true;
    }

    /**
     * Persist a recent-activity row so Overview can show "what just happened".
     * Returns the row id so the queued job can update status as it progresses.
     */
    protected function logManageActionStart(Server $server, string $taskName, string $streamTitle): string
    {
        $serviceActions = config('server_manage.service_actions', []);
        $dangerous = config('server_manage.dangerous_actions', []);

        // Best-effort label: try the action key after the colon (e.g. "manage-action:reload_nginx").
        $label = $streamTitle;
        if (preg_match('/^manage-action:(.+)$/', $taskName, $m)) {
            $key = $m[1];
            $label = (string) ($serviceActions[$key]['label'] ?? $dangerous[$key]['label'] ?? $key);
        } elseif (str_starts_with($taskName, 'manage-config-preview')) {
            $label = __('Configuration preview');
        }

        $row = ServerManageAction::create([
            'server_id' => $server->id,
            'user_id' => auth()->id(),
            'task_name' => $taskName,
            'label' => $label,
            'status' => ServerManageAction::STATUS_QUEUED,
        ]);

        return $row->id;
    }
}
