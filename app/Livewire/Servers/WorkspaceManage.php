<?php

namespace App\Livewire\Servers;

use App\Jobs\AddEdgeProxyJob;
use App\Jobs\ApplyEdgeBackendConfigsJob;
use App\Jobs\RefreshServerInventoryJob;
use App\Jobs\RemoveEdgeProxyJob;
use App\Jobs\RevertServerWebserverSwitchJob;
use App\Jobs\ServerManageRemoteSshJob;
use App\Jobs\SwitchServerWebserverJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DismissesConsoleActionRun;
use App\Livewire\Servers\Concerns\ClonesServer;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use App\Livewire\Servers\Concerns\RunsServerInventoryProbe;
use App\Livewire\Sites\Show;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\ServerManageAction;
use App\Models\ServerSystemdServiceState;
use App\Modules\TaskRunner\ProcessOutput;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Servers\MiseInstallScriptBuilder;
use App\Services\Servers\ServerAptLockBash;
use App\Services\Servers\ServerDeployGitIdentity;
use App\Services\Servers\ServerManageSshExecutor;
use App\Services\Servers\ServerManageToolsReport;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Services\Servers\WebserverSwitchPreflight;
use App\Services\SshConnection;
use App\Support\Servers\EdgeProxyWorkspaceViewData;
use App\Support\Servers\ServerConsoleActionLookup;
use App\Support\Servers\WebserverWorkspaceViewData;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Component;

#[Layout('layouts.app')]
#[Lazy]
class WorkspaceManage extends Component
{
    use ClonesServer;
    use ConfirmsActionWithModal;
    use DismissesConsoleActionRun;
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;
    use RendersWorkspacePlaceholder;
    use RunsServerInventoryProbe;

    /** @var string Manage sub-page slug (see config server_manage.workspace_tabs). */
    public string $section = 'overview';

    public string $manage_auto_updates_interval = 'off';

    /**
     * Lazy-loaded list of mise's upstream-available versions per runtime. Empty
     * until the operator clicks "Load versions" on the Tools → mise card; then
     * cached for the lifetime of the Livewire component instance so the dropdown
     * doesn't re-SSH on every render. Shape: ['node' => ['22.7.0', '20.16.0', …], …].
     * Filtered to stable releases only (pre/rc/beta/alpha tags stripped).
     *
     * @var array<string, list<string>>
     */
    public array $mise_available_versions = [];

    /** Per-runtime "loading versions" state for the dropdown spinner. */
    public ?string $mise_loading_versions_for = null;

    /**
     * Cascade preview for a pending webserver switch — set by openSwitchWebserver()
     * when the operator clicks "Switch to <target>" on the web tab. Consumed by
     * the confirmation modal in group-web.blade.php. Null when no switch is pending.
     * Shape matches {@see WebserverSwitchPreflight::plan()}.
     *
     * @var array<string, mixed>|null
     */
    public ?array $switch_plan = null;

    /**
     * Target engine key while the switch modal is open and the preflight plan
     * is still loading. Null once {@see loadSwitchPlan()} finishes or cancel.
     */
    public ?string $switch_preflight_target = null;

    /** Opt-in: hand TLS to caddy auto-HTTPS at cutover. Greyed out for apache. */
    public bool $switch_tls_to_caddy = false;

    public ?string $remote_output = null;

    public ?string $remote_error = null;

    /**
     * When set, {@see syncManageRemoteTaskFromCache} polls cache until the queued SSH task finishes.
     */
    public ?string $manageRemoteTaskId = null;

    /** Set by {@see WorkspaceWebserver::repairCaddyPhpFpmUpstream()} before {@see runAllowlistedAction()}. */
    public ?string $allowlistedActionPhpVersion = null;

    /** Server default PHP when it differs from the Caddy upstream socket version. */
    public ?string $allowlistedActionPhpVersionFallback = null;

    /** Stale PHP version from Caddy upstream when site configs need rewriting. */
    public ?string $allowlistedActionUpstreamPhpVersion = null;

    /** Full task name for the in-flight queued SSH job (used to trigger post-run reprobe). */
    public ?string $manageRemoteTaskName = null;

    /** Action key (e.g. install_docker) while a Tools install/repair is in flight. */
    public ?string $pendingToolActionKey = null;

    /** True while a synchronous inventory reprobe runs after a mise action completes. */
    public bool $miseReprobePending = false;

    public string $git_deploy_identity_name = '';

    public string $git_deploy_identity_email = '';

    /** Manage → Tools sub-panel: `tools` (catalog list) or `runtimes` (mise). */
    public string $toolsPanel = 'tools';

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

        if ($section === 'updates' && Feature::active('workspace.patch_advisor')) {
            $this->redirect(route('servers.patches', $server), navigate: true);

            return;
        }

        if ($section === 'configuration') {
            $this->redirect(route('servers.configuration', ['server' => $server]), navigate: true);

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
        $this->manage_auto_updates_interval = (string) ($meta['manage_auto_updates_interval'] ?? 'off');

        if ($section === 'tools') {
            $this->hydrateGitDeployIdentityForm();
        }
    }

    public function setToolsPanel(string $panel): void
    {
        if (! in_array($panel, ['tools', 'runtimes'], true)) {
            return;
        }

        $this->toolsPanel = $panel;
    }

    protected function hydrateGitDeployIdentityForm(): void
    {
        $identity = app(ServerDeployGitIdentity::class);
        $defaults = $identity->defaults($this->server);
        $meta = is_array($this->server->meta) ? $this->server->meta : [];
        $git = is_array($meta['manage_tools']['git'] ?? null) ? $meta['manage_tools']['git'] : [];

        $this->git_deploy_identity_name = is_string($git['user_name'] ?? null) && trim($git['user_name']) !== ''
            ? trim($git['user_name'])
            : $defaults['name'];
        $this->git_deploy_identity_email = is_string($git['user_email'] ?? null) && trim($git['user_email']) !== ''
            ? trim($git['user_email'])
            : $defaults['email'];
    }

    public function saveDeployGitIdentity(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot change server manage settings.'));

            return;
        }

        $this->validate([
            'git_deploy_identity_name' => ['required', 'string', 'max:120'],
            'git_deploy_identity_email' => ['required', 'email', 'max:190'],
        ]);

        if (! $this->serverOpsReady()) {
            $this->toastError(__('Provisioning and SSH must be ready before running actions.'));

            return;
        }

        $identity = app(ServerDeployGitIdentity::class);
        $deployUser = $identity->deployUser($this->server);
        if ($deployUser === null) {
            $this->toastError(__('This server has no deploy user configured.'));

            return;
        }

        $name = trim($this->git_deploy_identity_name);
        $email = trim($this->git_deploy_identity_email);

        $this->dispatchQueuedManageScript(
            $this->server->fresh() ?? $this->server,
            'manage-action:set_deploy_git_identity',
            $identity->buildSetScript($deployUser, $name, $email),
            60,
            __('Deploy user Git identity saved.'),
            __('TaskRunner (SSH)').' — '.__('Git identity'),
            __('Git identity'),
        );
    }

    public function applyDefaultDeployGitIdentity(): void
    {
        $defaults = app(ServerDeployGitIdentity::class)->defaults($this->server);
        $this->git_deploy_identity_name = $defaults['name'];
        $this->git_deploy_identity_email = $defaults['email'];
        $this->saveDeployGitIdentity();
    }

    public function saveManageMetadata(): void
    {
        $this->authorize('update', $this->server);
        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot change server manage settings.'));

            return;
        }

        $this->validate([
            'manage_auto_updates_interval' => ['required', 'string', 'in:'.implode(',', array_keys(config('server_manage.auto_update_intervals', [])))],
        ]);

        $meta = $this->server->meta ?? [];
        $meta['manage_auto_updates_interval'] = $this->manage_auto_updates_interval;

        $this->server->update(['meta' => $meta]);
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
        if ($key === 'apply_edge_backend_configs') {
            $this->applyEdgeBackendConfigs();

            return;
        }
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
        if ($key === 'repair_caddy_php_fpm_upstream') {
            $v = $this->allowlistedActionPhpVersion;
            if (! is_string($v) || preg_match('/^\d+\.\d+$/', $v) !== 1) {
                $this->remote_error = __('Could not determine PHP version for this repair.');

                return;
            }
            $script = 'export DPLY_PHP_VERSION='.escapeshellarg($v)."\n".$script;
            $upstream = $this->allowlistedActionUpstreamPhpVersion;
            if (is_string($upstream) && preg_match('/^\d+\.\d+$/', $upstream) === 1 && $upstream !== $v) {
                $script = 'export DPLY_UPSTREAM_PHP_VERSION='.escapeshellarg($upstream)."\n".$script;
            }
        } elseif (in_array($key, ['restart_php_fpm', 'reload_php_fpm'], true)) {
            $v = (string) ($meta['default_php_version'] ?? '8.3');
            if (! preg_match('/^\d+\.\d+$/', $v)) {
                $v = '8.3';
            }
            $script = 'export DPLY_PHP_VERSION='.escapeshellarg($v)."\n".$script;
        }
        if (str_starts_with($key, 'mysql_') && ! empty($meta['manage_internal_db_password']) && is_string($meta['manage_internal_db_password'])) {
            $script = 'export DPLY_DB_PASSWORD='.escapeshellarg($meta['manage_internal_db_password'])."\n".$script;
        }
        if (str_contains($script, '__DPLY_DEPLOY_USER__')) {
            $deployUser = trim((string) ($this->server->ssh_user ?? '')) !== ''
                ? (string) $this->server->ssh_user
                : (string) config('server_provision.deploy_ssh_user', 'dply');
            $script = str_replace('__DPLY_DEPLOY_USER__', $deployUser, $script);
        }

        $this->pendingToolActionKey = $key;

        try {
            $server = $this->server->fresh();
            $timeout = isset($def['timeout']) ? (int) $def['timeout'] : null;
            $flash = ($def['label'] ?? $key).' '.__('finished.');
            $label = (string) ($def['label'] ?? $key);
            $taskName = 'manage-action:'.$key;

            if ($this->shouldQueueManageRemoteTasks()) {
                $this->dispatchQueuedManageScript(
                    $server,
                    $taskName,
                    $script,
                    $timeout,
                    $flash,
                    __('TaskRunner (SSH)').' — '.$label,
                    $label,
                );

                return;
            }

            $logId = $this->logManageActionStart($server, $taskName, $label);
            ServerManageAction::query()
                ->where('id', $logId)
                ->update(['status' => ServerManageAction::STATUS_RUNNING]);

            // Sync path — seed the ConsoleAction row so the banner picks it up
            // in real time, then stream output lines into it as they arrive
            // alongside the existing remote_output buffer.
            $consoleId = $this->seedManageConsoleAction($server, $label);
            $emitter = new ConsoleEmitter($consoleId);
            DB::table('console_actions')->where('id', $consoleId)->update([
                'status' => ConsoleAction::STATUS_RUNNING,
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
                    $taskName,
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
                // Some daemons (e.g. `lshttpd -t`) write diagnostics into their
                // own log file rather than stdout/stderr, so the streaming
                // callback never fires and the banner shows "No output
                // recorded." Drop a placeholder line so the operator at least
                // sees that the command finished cleanly.
                if (trim((string) $this->remote_output) === '') {
                    $emitter->success(__('Command finished with no terminal output.'), 'dply');
                }
                DB::table('console_actions')->where('id', $consoleId)->update([
                    'status' => ConsoleAction::STATUS_COMPLETED,
                    'finished_at' => now(),
                    'error' => null,
                    'updated_at' => now(),
                ]);
                $this->toastSuccess($flash);
            } catch (\Throwable $inner) {
                DB::table('console_actions')->where('id', $consoleId)->update([
                    'status' => ConsoleAction::STATUS_FAILED,
                    'finished_at' => now(),
                    'error' => mb_substr($inner->getMessage(), 0, 2000),
                    'updated_at' => now(),
                ]);
                throw $inner;
            }
        } catch (\Throwable $e) {
            $this->remote_error = $e->getMessage();
        } finally {
            if (! $this->shouldQueueManageRemoteTasks()) {
                $this->pendingToolActionKey = null;
            }
        }
    }

    /**
     * Install a runtime version under the deploy user's mise and activate it as
     * the global default (`mise use --global`). Without activation, mise warns
     * that the version is "installed but not activated". The Tools tab's
     * "Install & activate" button is wired here.
     */
    public function miseInstallRuntime(string $runtime, string $version): void
    {
        $this->dispatchMiseRuntimeAction(
            runtime: $runtime,
            version: $version,
            kind: 'install',
            taskName: 'mise-runtime:install',
            labelTemplate: __('Installing and activating :runtime :version'),
        );
    }

    /**
     * Uninstall a runtime version from the deploy user's mise. Blocked when
     * the requested version is the current global default — operator must
     * pick a new default first (mise itself errors out the same way).
     */
    public function miseUninstallRuntime(string $runtime, string $version): void
    {
        $current = $this->miseCurrentRuntimeDefault($runtime);
        if ($current !== null && $current === trim($version)) {
            $this->toastError(__('Cannot uninstall :runtime :version while it is the global default — set a different version as default first.', [
                'runtime' => $runtime,
                'version' => $version,
            ]));

            return;
        }

        $this->dispatchMiseRuntimeAction(
            runtime: $runtime,
            version: $version,
            kind: 'uninstall',
            taskName: 'mise-runtime:uninstall',
            labelTemplate: __('Uninstalling :runtime :version'),
        );
    }

    /**
     * Open the confirmation modal before uninstalling a mise runtime version.
     */
    public function promptMiseUninstallRuntime(string $runtime, string $version): void
    {
        $runtime = strtolower(trim($runtime));
        $version = trim($version);

        if ($version === '') {
            return;
        }

        $catalog = config('server_manage.mise_runtimes', []);
        $label = is_array($catalog[$runtime] ?? null)
            ? (string) ($catalog[$runtime]['label'] ?? $runtime)
            : $runtime;

        $confirm = __('Uninstall :runtime :version? The deploy user\'s mise data directory drops the install; sites already pinned to this version will fall back to the runtime default.', [
            'runtime' => $label,
            'version' => $version,
        ]);

        $this->openConfirmActionModal(
            'miseUninstallRuntime',
            [$runtime, $version],
            __('Uninstall :v', ['v' => $version]),
            $confirm,
            __('Uninstall :runtime :v', ['runtime' => $label, 'v' => $version]),
            true,
        );
    }

    /**
     * Set a runtime version as the deploy user's global default (`mise use
     * --global`). Installs the version as a side-effect if it isn't already
     * present, so this doubles as a "switch to this version" affordance.
     */
    public function miseSetRuntimeDefault(string $runtime, string $version): void
    {
        $this->dispatchMiseRuntimeAction(
            runtime: $runtime,
            version: $version,
            kind: 'default',
            taskName: 'mise-runtime:default',
            labelTemplate: __('Setting :runtime :version as default'),
        );
    }

    /**
     * Shared plumbing for the three mise runtime actions. Validates inputs,
     * builds the right bash via {@see MiseInstallScriptBuilder}, and dispatches
     * through the queued manage-action pipeline so output flows into the
     * existing console-action banner.
     */
    protected function dispatchMiseRuntimeAction(
        string $runtime,
        string $version,
        string $kind,
        string $taskName,
        string $labelTemplate,
    ): void {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot manage server runtimes.'));

            return;
        }

        $runtime = strtolower(trim($runtime));
        $version = trim($version);

        if (! in_array($runtime, MiseInstallScriptBuilder::supportedRuntimes(), true)) {
            $this->toastError(__('Unsupported runtime: :runtime.', ['runtime' => $runtime]));

            return;
        }

        // Loose version validation — accept semver-ish, plain digits, and mise
        // shorthand like "lts" or "20". Reject anything with shell metacharacters
        // even though the builder escapes via `escapeshellarg`, since the value
        // also lands in console-action labels we surface to the operator.
        if ($version === '' || ! preg_match('/^[A-Za-z0-9._-]+$/', $version)) {
            $this->toastError(__('Invalid version: :version.', ['version' => $version]));

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->toastError(__('Provisioning and SSH must be ready before managing runtimes.'));

            return;
        }

        $deployUser = trim((string) ($this->server->ssh_user ?? '')) !== ''
            ? (string) $this->server->ssh_user
            : (string) config('server_provision.deploy_ssh_user', 'dply');
        if ($deployUser === '' || $deployUser === 'root') {
            $this->toastError(__('This server has no deploy user configured; cannot manage mise runtimes.'));

            return;
        }

        $builder = app(MiseInstallScriptBuilder::class);
        $runtimeLines = match ($kind) {
            'install', 'default' => $builder->installRuntimeForUserLines($deployUser, $runtime, $version),
            'uninstall' => $builder->uninstallRuntimeVersionForUserLines($deployUser, $runtime, $version),
            default => [],
        };
        $lines = $kind === 'uninstall'
            ? $runtimeLines
            : array_merge($builder->activateForUserLines($deployUser), $runtimeLines);
        if ($lines === []) {
            $this->toastError(__('Could not build the runtime script for :runtime :version.', [
                'runtime' => $runtime,
                'version' => $version,
            ]));

            return;
        }

        // The builder emits ash-safe lines; join them with set -e so a mid-script
        // failure surfaces in the banner rather than silently passing.
        $script = "set -e\n".implode("\n", $lines)."\n";
        $label = strtr($labelTemplate, [':runtime' => $runtime, ':version' => $version]);

        $this->dispatchQueuedManageScript(
            $this->server->fresh() ?? $this->server,
            $taskName.':'.$runtime.'@'.$version,
            $script,
            300, // mise installs (Python/Ruby builds) can take a few minutes.
            $label.' '.__('finished.'),
            __('TaskRunner (SSH)').' — '.$label,
            $label,
        );
    }

    /**
     * Populate {@see $mise_available_versions} for one runtime by SSHing
     * `mise ls-remote <tool>` as the deploy user. Filters to stable releases
     * (drops pre/rc/beta/alpha/dev tags) and caps the dropdown size — there's
     * no value in showing the operator hundreds of Node patch releases.
     *
     * Runs synchronously and blocks the Livewire request for ~1–3s on a warm
     * mise plugin; first-ever invocation can take longer if mise has to clone
     * the plugin repo. Errors surface as a toast and a null entry so the UI
     * can offer "try again" without re-fetching on every render.
     */
    public function loadMiseAvailableVersions(string $runtime): void
    {
        $this->authorize('update', $this->server);

        $runtime = strtolower(trim($runtime));
        if (! in_array($runtime, MiseInstallScriptBuilder::supportedRuntimes(), true)) {
            $this->toastError(__('Unsupported runtime: :runtime.', ['runtime' => $runtime]));

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->toastError(__('Provisioning and SSH must be ready before loading versions.'));

            return;
        }

        $deployUser = trim((string) ($this->server->ssh_user ?? '')) !== ''
            ? (string) $this->server->ssh_user
            : (string) config('server_provision.deploy_ssh_user', 'dply');
        if ($deployUser === '' || $deployUser === 'root') {
            $this->toastError(__('This server has no deploy user configured; cannot list mise versions.'));

            return;
        }

        $this->mise_loading_versions_for = $runtime;

        try {
            $userArg = escapeshellarg($deployUser);
            $toolArg = escapeshellarg($runtime);
            $script = "sudo -u {$userArg} -i mise ls-remote {$toolArg} 2>/dev/null || true";
            $ssh = new SshConnection($this->server, 'root');
            $output = $ssh->exec('/bin/sh -c '.escapeshellarg($script), 30);
            $ssh->disconnect();

            $versions = $this->filterStableMiseVersions($output);
            $this->mise_available_versions[$runtime] = $versions;

            if ($versions === []) {
                $this->toastError(__(':runtime: no stable versions returned. Is the mise plugin installed?', ['runtime' => $runtime]));
            }
        } catch (\Throwable $e) {
            $this->toastError(__(':runtime versions: :err', ['runtime' => $runtime, 'err' => $e->getMessage()]));
        } finally {
            $this->mise_loading_versions_for = null;
        }
    }

    /**
     * Strip pre-release tags and dedupe `mise ls-remote` output down to the
     * shortlist the dropdown actually wants. Versions sort descending so the
     * latest is at the top.
     *
     * @return list<string>
     */
    protected function filterStableMiseVersions(string $output): array
    {
        $versions = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // Drop anything that doesn't start with a digit (e.g. "system",
            // header noise) and any release tagged pre/rc/beta/alpha/dev.
            if (! preg_match('/^\d/', $line)) {
                continue;
            }
            if (preg_match('/-(?:pre|rc|beta|alpha|dev|nightly|preview|next)/i', $line)) {
                continue;
            }
            $versions[] = $line;
        }
        $versions = array_values(array_unique($versions));
        usort($versions, fn (string $a, string $b) => version_compare($b, $a));

        // Cap at a reasonable size — operators rarely need anything older.
        return array_slice($versions, 0, 60);
    }

    /**
     * Read the current global default for a runtime from the cached probe
     * snapshot. Returns null when the runtime hasn't been probed yet or has
     * no default. Used by miseUninstallRuntime() to refuse to uninstall the
     * active default (mise itself would refuse anyway, but failing fast in
     * Livewire gives a friendlier toast than the SSH banner does).
     */
    protected function miseCurrentRuntimeDefault(string $runtime): ?string
    {
        $meta = $this->server->fresh()->meta ?? [];
        $runtimes = is_array($meta['manage_mise_runtimes'] ?? null) ? $meta['manage_mise_runtimes'] : [];
        $entry = $runtimes[$runtime] ?? null;
        if (! is_array($entry)) {
            return null;
        }
        $active = $entry['active'] ?? null;

        return is_string($active) && $active !== '' ? $active : null;
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

        if (WebserverWorkspaceViewData::isComingSoonEngine($target)) {
            $label = WebserverWorkspaceViewData::webserverCatalog()[$target]['label'] ?? $target;
            $this->toastError(__(':engine switching is coming soon.', ['engine' => $label]));

            return;
        }

        $this->switch_plan = null;
        $this->switch_preflight_target = $target;
        $this->switch_tls_to_caddy = false;
        $this->dispatch('open-modal', 'webserver-switch-modal');
    }

    /**
     * Compute the switch cascade preview after the modal opens. Kept separate
     * from {@see openSwitchWebserver()} so the confirmation shell appears
     * immediately while site/profile preflight runs.
     */
    public function loadSwitchPlan(): void
    {
        $target = $this->switch_preflight_target;
        if ($target === null || $this->switch_plan !== null) {
            return;
        }

        $this->authorize('update', $this->server);

        $plan = app(WebserverSwitchPreflight::class)->plan($this->server, $target);

        // Operator closed the modal while preflight was running.
        if ($this->switch_preflight_target !== $target) {
            return;
        }

        $this->switch_plan = $plan;
        $this->switch_tls_to_caddy = false;
        $this->switch_preflight_target = null;
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
        $this->switch_preflight_target = null;
        $this->switch_tls_to_caddy = false;
        $this->dispatch('close-modal', 'webserver-switch-modal');
        $this->toastSuccess(__('Webserver switch queued. Progress shows in the banner above.'));
    }

    /**
     * Seed a queued `ConsoleAction` row for the upcoming `webserver_switch` job
     * so the banner-static partial picks it up on the next render — without
     * waiting for the worker to claim the job. Auto-dismisses prior terminal +
     * stale-running rows so the operator sees only the run they just started.
     * Mirrors {@see Show::seedQueuedConsoleAction()} but
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

        $action = ConsoleAction::query()->create([
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'kind' => 'webserver_switch',
            'status' => ConsoleAction::STATUS_QUEUED,
            'label' => $label,
            'user_id' => request()->user()?->id,
            'output' => $output,
        ]);

        app(ServerConsoleActionLookup::class)->forget($this->server);

        return $action;
    }

    /**
     * Discard the pending switch — closes the modal, leaves the server untouched.
     */
    public function cancelSwitchWebserver(): void
    {
        $this->switch_plan = null;
        $this->switch_preflight_target = null;
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

        $row->forceFill([
            'status' => ConsoleAction::STATUS_FAILED,
            'finished_at' => now(),
            'error' => 'Aborted by operator',
            'dismissed_at' => now(),
        ])->save();

        $this->dispatchWebserverSwitchRevert($row, __('Stopping the switch and reverting :to → :from. Progress shows in the banner.'));
    }

    /**
     * After a switch fails (e.g. cutover could not start Caddy), uninstall the
     * partial target and bring the original webserver back on :80.
     */
    public function cleanupFailedWebserverSwitch(string $runId): void
    {
        $this->authorize('update', $this->server);

        $row = ConsoleAction::query()
            ->where('id', $runId)
            ->where('subject_type', $this->server->getMorphClass())
            ->where('subject_id', $this->server->getKey())
            ->where('kind', 'webserver_switch')
            ->whereNull('dismissed_at')
            ->first();

        if ($row === null || $row->isInFlight()) {
            $this->toastError(__('No failed webserver switch to clean up.'));

            return;
        }

        if ($row->status !== ConsoleAction::STATUS_FAILED) {
            $this->toastError(__('Cleanup is only available for a failed switch.'));

            return;
        }

        $row->forceFill(['dismissed_at' => now()])->save();

        $this->dispatchWebserverSwitchRevert($row, __('Cleaning up the failed switch and restoring :from on :80. Progress shows in the banner.'));
    }

    /**
     * @return array{from: string, to: string}|null
     */
    private function webserverSwitchEndpointsFromRow(ConsoleAction $row): ?array
    {
        $output = is_array($row->output) ? $row->output : [];
        $meta = is_array($output['meta'] ?? null) ? $output['meta'] : [];
        $serverWebserver = strtolower((string) ($this->server->meta['webserver'] ?? 'nginx'));
        $from = strtolower((string) ($meta['from'] ?? $serverWebserver));
        $to = strtolower((string) ($meta['to'] ?? ''));

        if ($to === '' && is_string($row->label) && preg_match('/→\s*(\S+)/u', $row->label, $m)) {
            $to = strtolower((string) $m[1]);
        }

        if ($to === '' || $to === $from) {
            return null;
        }

        return ['from' => $from, 'to' => $to];
    }

    private function dispatchWebserverSwitchRevert(ConsoleAction $row, string $toastTemplate): void
    {
        $endpoints = $this->webserverSwitchEndpointsFromRow($row);
        if ($endpoints === null) {
            $this->toastError(__('Cannot determine which webserver to restore from this switch run.'));

            return;
        }

        $from = $endpoints['from'];
        $to = $endpoints['to'];

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

        $this->toastSuccess(__($toastTemplate, ['to' => $to, 'from' => $from]));
    }

    /**
     * Required by {@see DismissesConsoleActionRun}: identifies which model the
     * banner is scoped to. WorkspaceManage's banner shows server-level runs
     * (webserver_switch, etc.), so the subject is the server.
     */
    protected function consoleActionSubject(): Model
    {
        return $this->server;
    }

    /**
     * True when there's a queued/running webserver_switch ConsoleAction for this
     * server. Used to disable the switch CTAs and short-circuit re-entry.
     */
    public function hasInflightWebserverSwitch(): bool
    {
        return app(ServerConsoleActionLookup::class)->hasInflightWebserverSwitch($this->server);
    }

    /**
     * Inflight check for edge-proxy add/remove. Same shape as
     * {@see hasInflightWebserverSwitch()} but scoped to the `edge_proxy`
     * console-action kind so the two banners don't shadow each other.
     */
    public function hasInflightEdgeProxyAction(): bool
    {
        return app(ServerConsoleActionLookup::class)->hasInflightEdgeProxy($this->server);
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
        $catalog = EdgeProxyWorkspaceViewData::edgeProxyCatalog();
        if (! isset($catalog[$target])) {
            $this->toastError(__('Unknown edge proxy: :t.', ['t' => $target]));

            return;
        }

        if (EdgeProxyWorkspaceViewData::isComingSoonEdgeProxy($target)) {
            $label = $catalog[$target]['label'] ?? $target;
            $this->toastError(__(':engine edge proxy is coming soon.', ['engine' => $label]));

            return;
        }

        if (! in_array($target, EdgeProxyWorkspaceViewData::installableEdgeProxies(), true)) {
            $label = $catalog[$target]['label'] ?? $target;
            $this->toastError(__(':engine edge proxy is coming soon.', ['engine' => $label]));

            return;
        }

        if ($this->hasInflightEdgeProxyAction() || $this->hasInflightWebserverSwitch()) {
            $this->toastError(__('Another webserver action is in flight — wait for it to finish.'));

            return;
        }

        $currentEdge = $this->server->edgeProxy();
        $isSwitch = $currentEdge !== null && $currentEdge !== $target;
        $targetLabel = $catalog[$target]['label'] ?? $target;

        $this->seedQueuedEdgeProxyAction(
            label: $isSwitch
                ? __('Switching edge proxy to :target …', ['target' => $targetLabel])
                : __('Adding edge proxy: :target …', ['target' => $targetLabel]),
            meta: ['op' => $isSwitch ? 'switch' : 'add', 'target' => $target, 'from' => $currentEdge],
        );

        AddEdgeProxyJob::dispatch(
            serverId: $this->server->id,
            target: $target,
            userId: auth()->id(),
        );

        $this->toastSuccess(__('Edge proxy queued. Progress shows in the banner above.'));
    }

    /**
     * Rebuild every site's Caddy backend + TLS configs and the active edge
     * routing file (Envoy / HAProxy / Traefik). Repair when preview URLs or
     * HTTPS fronts drift after cutover.
     */
    public function applyEdgeBackendConfigs(): void
    {
        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->toastError(__('Deployers cannot run service actions on servers.'));

            return;
        }

        $edge = $this->server->edgeProxy();
        if ($edge === null) {
            $this->toastError(__('No edge proxy is active on this server.'));

            return;
        }

        if ($this->hasInflightEdgeProxyAction() || $this->hasInflightWebserverSwitch()) {
            $this->toastError(__('Another webserver action is in flight — wait for it to finish.'));

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->toastError(__('Provisioning and SSH must be ready before running actions.'));

            return;
        }

        $catalog = EdgeProxyWorkspaceViewData::edgeProxyCatalog();
        $edgeLabel = $catalog[$edge]['label'] ?? ucfirst($edge);

        $this->seedQueuedEdgeProxyAction(
            label: __('Applying webserver config (edge backends + :edge routing)…', ['edge' => $edgeLabel]),
            meta: ['op' => 'apply_backends', 'target' => $edge],
        );

        ApplyEdgeBackendConfigsJob::dispatch(
            serverId: $this->server->id,
            userId: auth()->id(),
        );

        $this->toastSuccess(__('Edge backend sync queued. Progress shows in the banner above.'));
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

        $action = ConsoleAction::query()->create([
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'kind' => 'edge_proxy',
            'status' => ConsoleAction::STATUS_QUEUED,
            'label' => $label,
            'user_id' => request()->user()?->id,
            'output' => $output,
        ]);

        app(ServerConsoleActionLookup::class)->forget($this->server);

        return $action;
    }

    public function pollManageWorkspace(): void
    {
        $this->syncManageRemoteTaskFromCache();
    }

    /**
     * wire:init target — queue a background inventory probe when Manage lands with
     * SSH ready but no probe snapshot yet (common right after provision).
     */
    public function maybeRefreshInventoryProbeOnLoad(): void
    {
        if (! (bool) config('server_manage.inventory_probe_refresh_on_load', true)) {
            return;
        }

        $this->attemptAutoInventoryProbeRefresh();
    }

    /**
     * wire:poll target while provisioning is in flight or probe meta is still empty.
     * Refreshes the server row from the database and re-attempts the auto-refresh
     * dispatch once SSH becomes ready.
     */
    public function pollManageInventoryState(): void
    {
        $this->server->refresh();
        $this->attemptAutoInventoryProbeRefresh();
    }

    protected function attemptAutoInventoryProbeRefresh(): void
    {
        if (! $this->shouldAutoRefreshInventoryProbe()) {
            return;
        }

        $cacheKey = 'server-inventory-probe:auto:'.$this->server->id;
        if (! Cache::add($cacheKey, 1, now()->addMinutes(2))) {
            return;
        }

        RefreshServerInventoryJob::dispatch((string) $this->server->id);
    }

    protected function shouldAutoRefreshInventoryProbe(): bool
    {
        if ($this->currentUserIsDeployer()) {
            return false;
        }

        if (! auth()->user()?->can('update', $this->server)) {
            return false;
        }

        if (! $this->serverOpsReady()) {
            return false;
        }

        $meta = is_array($this->server->meta) ? $this->server->meta : [];
        $checkedAt = $meta['inventory_checked_at'] ?? null;

        return ! is_string($checkedAt) || trim($checkedAt) === '';
    }

    /**
     * @return array<string, array{kind: string, version: string, status: string, message: string}>
     */
    protected function activeMiseRuntimeOperations(): array
    {
        $rows = ServerManageAction::query()
            ->where('server_id', $this->server->id)
            ->where('task_name', 'like', 'mise-runtime:%')
            ->whereIn('status', [
                ServerManageAction::STATUS_QUEUED,
                ServerManageAction::STATUS_RUNNING,
            ])
            ->orderByDesc('created_at')
            ->get(['task_name', 'status']);

        $ops = [];

        foreach ($rows as $row) {
            if (! preg_match('/^mise-runtime:(install|uninstall|default):([^@]+)@(.+)$/', (string) $row->task_name, $matches)) {
                continue;
            }

            $runtime = strtolower($matches[2]);
            if (isset($ops[$runtime])) {
                continue;
            }

            $kind = $matches[1];
            $version = $matches[3];

            $ops[$runtime] = [
                'kind' => $kind,
                'version' => $version,
                'status' => (string) $row->status,
                'message' => match ($kind) {
                    'install' => __('Installing :version…', ['version' => $version]),
                    'uninstall' => __('Uninstalling :version…', ['version' => $version]),
                    'default' => __('Setting :version as default…', ['version' => $version]),
                    default => __('Working…'),
                },
            ];
        }

        return $ops;
    }

    /**
     * @return array<string, array{status: string, message: string, label: string}>
     */
    public function activeManageActionOperations(): array
    {
        return $this->activeToolActionOperations();
    }

    /**
     * @return array<string, array{status: string, message: string, label: string}>
     */
    protected function activeToolActionOperations(): array
    {
        $rows = ServerManageAction::query()
            ->where('server_id', $this->server->id)
            ->where('task_name', 'like', 'manage-action:%')
            ->whereIn('status', [
                ServerManageAction::STATUS_QUEUED,
                ServerManageAction::STATUS_RUNNING,
            ])
            ->orderByDesc('created_at')
            ->get(['task_name', 'status', 'label']);

        $ops = [];

        foreach ($rows as $row) {
            if (! preg_match('/^manage-action:(.+)$/', (string) $row->task_name, $matches)) {
                continue;
            }

            $key = $matches[1];
            if (isset($ops[$key])) {
                continue;
            }

            $status = (string) $row->status;
            $ops[$key] = [
                'status' => $status,
                'label' => (string) $row->label,
                'message' => $this->toolActionBusyMessage($key, $status, (string) $row->label),
            ];
        }

        if ($this->manageRemoteTaskId !== null
            && $this->manageRemoteTaskId !== ''
            && is_string($this->manageRemoteTaskName)
            && preg_match('/^manage-action:(.+)$/', $this->manageRemoteTaskName, $matches)) {
            $key = $matches[1];
            if (! isset($ops[$key])) {
                $payload = Cache::get(ServerManageRemoteSshJob::cacheKey($this->manageRemoteTaskId));
                $status = is_array($payload) ? (string) ($payload['status'] ?? 'queued') : 'queued';

                if (in_array($status, ['queued', 'running'], true)) {
                    $label = config('server_manage.service_actions.'.$key.'.label')
                        ?? config('server_manage.dangerous_actions.'.$key.'.label')
                        ?? $key;

                    $ops[$key] = [
                        'status' => $status,
                        'label' => is_string($label) ? $label : $key,
                        'message' => $this->toolActionBusyMessage($key, $status, is_string($label) ? $label : $key),
                    ];
                }
            }
        }

        if ($this->pendingToolActionKey !== null
            && $this->pendingToolActionKey !== ''
            && ! isset($ops[$this->pendingToolActionKey])) {
            $key = $this->pendingToolActionKey;
            $label = config('server_manage.service_actions.'.$key.'.label')
                ?? config('server_manage.dangerous_actions.'.$key.'.label')
                ?? $key;

            $ops[$key] = [
                'status' => ServerManageAction::STATUS_RUNNING,
                'label' => is_string($label) ? $label : $key,
                'message' => $this->toolActionBusyMessage($key, ServerManageAction::STATUS_RUNNING, is_string($label) ? $label : $key),
            ];
        }

        return $ops;
    }

    protected function toolActionBusyMessage(string $key, string $status, string $label): string
    {
        if ($status === ServerManageAction::STATUS_QUEUED) {
            return __('Queuing :action…', ['action' => $label]);
        }

        if (str_starts_with($key, 'install_')) {
            return __('Installing :action…', ['action' => $label]);
        }

        if (str_starts_with($key, 'repair_') || str_starts_with($key, 'update_')) {
            return __('Updating :action…', ['action' => $label]);
        }

        return __('Running :action…', ['action' => $label]);
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

        $taskName = $this->manageRemoteTaskName;
        $this->finalizeManageRemoteTaskLog($status, $taskName);

        if ($status === 'finished') {
            $flash = $payload['flash_success'] ?? null;
            if (is_string($flash) && $flash !== '') {
                $this->toastSuccess($flash);
            }

            if ($this->shouldRefreshInventoryAfterRemoteTask($taskName)) {
                $this->runPostMiseInventoryRefresh();
            }

            if ($this->section === 'tools' && $taskName === 'manage-action:set_deploy_git_identity') {
                $this->hydrateGitDeployIdentityForm();
            }
        }

        Cache::forget(ServerManageRemoteSshJob::cacheKey($this->manageRemoteTaskId));
        $this->manageRemoteTaskId = null;
        $this->manageRemoteTaskName = null;
        $this->pendingToolActionKey = null;
    }

    protected function finalizeManageRemoteTaskLog(string $cacheStatus, ?string $taskName): void
    {
        if (! is_string($taskName) || $taskName === '') {
            return;
        }

        if (! in_array($cacheStatus, ['finished', 'failed'], true)) {
            return;
        }

        $rowStatus = $cacheStatus === 'finished'
            ? ServerManageAction::STATUS_FINISHED
            : ServerManageAction::STATUS_FAILED;

        ServerManageAction::query()
            ->where('server_id', $this->server->id)
            ->where('task_name', $taskName)
            ->whereIn('status', [
                ServerManageAction::STATUS_QUEUED,
                ServerManageAction::STATUS_RUNNING,
            ])
            ->update([
                'status' => $rowStatus,
                'finished_at' => now(),
            ]);
    }

    protected function shouldRefreshInventoryAfterRemoteTask(?string $taskName): bool
    {
        if (! is_string($taskName) || $taskName === '') {
            return false;
        }

        if (str_starts_with($taskName, 'mise-runtime:')) {
            return true;
        }

        if (! preg_match('/^manage-action:(.+)$/', $taskName, $matches)) {
            return false;
        }

        $key = $matches[1];
        if ($key === 'set_deploy_git_identity') {
            return true;
        }

        $entry = config('server_manage.service_actions', [])[$key]
            ?? config('server_manage.dangerous_actions', [])[$key]
            ?? null;

        return is_array($entry) && (bool) ($entry['rerun_probe_after_finish'] ?? false);
    }

    protected function shouldRefreshWebserverLiveStateAfterRemoteTask(?string $taskName): bool
    {
        if (! is_string($taskName) || $taskName === '') {
            return false;
        }

        if (! preg_match('/^manage-action:(.+)$/', $taskName, $matches)) {
            return false;
        }

        $key = $matches[1];
        $entry = config('server_manage.service_actions', [])[$key]
            ?? config('server_manage.dangerous_actions', [])[$key]
            ?? null;

        return is_array($entry) && (bool) ($entry['refresh_webserver_live_state_after_finish'] ?? false);
    }

    protected function runPostMiseInventoryRefresh(): void
    {
        if (! $this->canRunInventoryProbe()) {
            return;
        }

        $this->miseReprobePending = true;

        try {
            $this->refreshServerInventoryDetails();
        } finally {
            $this->miseReprobePending = false;
            $this->server->refresh();
        }
    }

    /**
     * Override the trait placeholder so the Manage sub-tab strip stays
     * visible (with the destination section highlighted) while the body
     * lazy-loads — only the content area below the sub-tabs skeletons.
     */
    public function placeholder(): View
    {
        return view('livewire.servers.partials.workspace-subtab-placeholder', [
            'server' => $this->server,
            'active' => 'manage',
            'title' => __('Manage'),
            'tabs' => $this->manageWorkspaceTabs(),
            'section' => $this->section,
            'routeName' => 'servers.manage',
            'idPrefix' => 'manage-tab-',
            'ariaLabel' => __('Manage categories'),
        ]);
    }

    public function render(ServerManageToolsReport $toolsReport): View
    {
        // No $this->server->refresh() here: Livewire re-resolves the bound
        // model from the database on every request (route binding on first
        // load, the Eloquent synthesizer on later updates), so the row is
        // already current at render time. The poll/action handlers that mutate
        // the server (pollManageInventoryState, saveManageMetadata,
        // runPostMiseInventoryRefresh) refresh it themselves, so refreshing
        // again here only doubled the `select * from servers` per render.
        $recentActions = $this->section === 'overview'
            ? ServerManageAction::query()
                ->where('server_id', $this->server->id)
                ->orderByDesc('created_at')
                ->limit(5)
                ->get()
            : collect();

        $serviceActions = config('server_manage.service_actions', []);

        // Quick-actions allowlist for the overview tile. Each key maps to one
        // or more systemd unit prefixes; the button is hidden if none of the
        // matching units exist on this server (e.g. a Valkey-only host
        // shouldn't surface "Reload NGINX" or "Restart PHP-FPM"). Lookup is
        // O(1) via the systemd-state table — populated by the inventory probe.
        $serviceActionUnitMatchers = [
            'reload_nginx' => ['nginx.service'],
            'restart_nginx' => ['nginx.service'],
            'restart_php_fpm' => ['php8.3-fpm.service', 'php8.2-fpm.service', 'php8.1-fpm.service', 'php8.4-fpm.service', 'php8.0-fpm.service', 'php7.4-fpm.service'],
            'reload_php_fpm' => ['php8.3-fpm.service', 'php8.2-fpm.service', 'php8.1-fpm.service', 'php8.4-fpm.service', 'php8.0-fpm.service', 'php7.4-fpm.service'],
            'restart_redis' => ['redis-server.service', 'redis.service'],
            // 'apt_update' has no service prerequisite — always available on Debian/Ubuntu.
        ];

        $installedUnits = ServerSystemdServiceState::query()
            ->where('server_id', $this->server->id)
            ->where(function ($q) {
                $q->whereNull('unit_file_state')
                    ->orWhere('unit_file_state', '!=', 'not-found');
            })
            ->pluck('unit')
            ->all();
        $installedUnitsSet = array_flip($installedUnits);

        $quickActionKeys = array_values(array_filter(
            array_keys($serviceActions),
            function (string $key) use ($serviceActionUnitMatchers, $installedUnitsSet): bool {
                if (! isset($serviceActionUnitMatchers[$key])) {
                    // No prerequisite declared (e.g. apt_update) — let it
                    // through, falls under "universal" maintenance actions.
                    return true;
                }
                foreach ($serviceActionUnitMatchers[$key] as $unit) {
                    if (isset($installedUnitsSet[$unit])) {
                        return true;
                    }
                }

                return false;
            },
        ));

        $activeMiseRuntimeOps = $this->section === 'tools'
            ? $this->activeMiseRuntimeOperations()
            : [];

        $activeToolActionOps = $this->section === 'tools'
            ? $this->activeToolActionOperations()
            : [];

        return view('livewire.servers.workspace-manage', [
            'configPreviews' => config('server_manage.config_previews', []),
            'serviceActions' => $serviceActions,
            'quickActionKeys' => $quickActionKeys,
            'dangerousActions' => config('server_manage.dangerous_actions', []),
            'autoUpdateIntervals' => config('server_manage.auto_update_intervals', []),
            'recentActions' => $recentActions,
            'toolsReport' => $this->section === 'tools'
                ? $toolsReport->build($this->server, $serviceActions)
                : null,
            'activeMiseRuntimeOps' => $activeMiseRuntimeOps,
            'activeToolActionOps' => $activeToolActionOps,
            'pendingToolActionKey' => $this->pendingToolActionKey,
            'miseReprobePending' => $this->miseReprobePending,
            'toolsPanel' => $this->toolsPanel,
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
            'manageTabs' => $this->manageWorkspaceTabs(),
        ]);
    }

    /**
     * @return array<string, array{label: string, icon: string}>
     */
    protected function manageWorkspaceTabs(): array
    {
        $tabs = config('server_manage.workspace_tabs', []);

        if (Feature::active('workspace.patch_advisor')) {
            unset($tabs['updates']);
        }

        return $tabs;
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
        $this->manageRemoteTaskName = $taskName;

        if (preg_match('/^manage-action:(.+)$/', $taskName, $matches)) {
            $this->pendingToolActionKey = $matches[1];
        }

        $inlineBash = ServerAptLockBash::wrapManageScript($inlineBash);

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
        ConsoleAction::query()
            ->where('subject_type', $server->getMorphClass())
            ->where('subject_id', $server->id)
            ->where('kind', 'manage_action')
            ->whereNull('dismissed_at')
            ->whereIn('status', [
                ConsoleAction::STATUS_COMPLETED,
                ConsoleAction::STATUS_FAILED,
            ])
            ->update(['dismissed_at' => now()]);

        $row = ConsoleAction::query()->create([
            'subject_type' => $server->getMorphClass(),
            'subject_id' => $server->id,
            'kind' => 'manage_action',
            'status' => ConsoleAction::STATUS_QUEUED,
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
