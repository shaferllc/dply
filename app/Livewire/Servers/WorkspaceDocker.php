<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Jobs\ServerManageRemoteSshJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Servers\Concerns\DismissesServerConsoleActionRun;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\RunsAllowlistedManageAction;
use App\Livewire\Servers\Concerns\RunsServerInventoryProbe;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Services\SshConnectionFactory;
use App\Support\Servers\DockerContainerShellSupport;
use App\Support\Servers\DockerWorkspaceViewData;
use App\Support\Servers\ServerDockerRemoteInspector;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Remote Docker Engine inspector over SSH — containers, images, volumes,
 * networks, compose projects, and cleanup.
 *
 * When {@see workspace.docker} is off but {@see workspace.docker_preview} is
 * on, the canonical /docker URL renders the coming-soon teaser in place of the
 * full workspace.
 */
#[Layout('layouts.app')]
class WorkspaceDocker extends Component
{
    use RequiresFeature;

    protected string $requiredFeature = 'workspace.docker';

    /** When true, render the coming-soon teaser instead of the full workspace. */
    public bool $comingSoonPreview = false;

    use ConfirmsActionWithModal;
    use DismissesServerConsoleActionRun;
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;
    use RunsAllowlistedManageAction;
    use RunsServerInventoryProbe;

    /** @var list<array{id: string, name: string, image: string, status: string, state: string, ports: string}>|null */
    public ?array $containers = null;

    public ?string $containersError = null;

    public bool $containersLoading = false;

    /** @var list<array{id: string, repository: string, tag: string, size: string, created: string}>|null */
    public ?array $images = null;

    public ?string $imagesError = null;

    public bool $imagesLoading = false;

    /** @var list<array{name: string, driver: string, scope: string}>|null */
    public ?array $volumes = null;

    public ?string $volumesError = null;

    public bool $volumesLoading = false;

    /** @var list<array{id: string, name: string, driver: string, scope: string}>|null */
    public ?array $networks = null;

    public ?string $networksError = null;

    public bool $networksLoading = false;

    /** @var list<array{name: string, status: string, config: string}>|null */
    public ?array $composeProjects = null;

    public ?string $composeError = null;

    public bool $composeLoading = false;

    /** @var list<array{type: string, total: string, active: string, size: string, reclaimable: string}>|null */
    public ?array $systemDf = null;

    public ?string $systemDfError = null;

    public bool $systemDfLoading = false;

    public string $pullImageInput = '';

    public ?string $logsModalContainerId = null;

    public ?string $logsModalContainerName = null;

    public string $logsModalContent = '';

    public ?string $logsModalError = null;

    public bool $logsModalLoading = false;

    public ?string $inspectModalContainerId = null;

    public ?string $inspectModalContainerName = null;

    public string $inspectModalContent = '';

    public ?string $inspectModalError = null;

    public bool $inspectModalLoading = false;

    public ?string $execModalContainerId = null;

    public ?string $execModalContainerName = null;

    public string $execModalCommand = '';

    public ?string $shellModalContainerId = null;

    public ?string $shellModalContainerName = null;

    public string $shellModalCommand = '';

    public ?string $shellModalError = null;

    public bool $shellModalRunning = false;

    /**
     * @var array<int, array{cmd: string, out: string, exit: ?int, error: ?string}>
     */
    public array $shellModalHistory = [];

    public ?string $composeLogsModalProject = null;

    public ?string $composeLogsModalConfig = null;

    public string $composeLogsModalContent = '';

    public ?string $composeLogsModalError = null;

    public bool $composeLogsModalLoading = false;

    #[Url(as: 'tab', except: 'overview', history: true)]
    public string $workspace_tab = 'overview';

    /** @var list<string> */
    private const TABS = ['overview', 'containers', 'images', 'volumes', 'networks', 'compose', 'maintenance'];

    public function mount(Server $server): void
    {
        if (! Feature::active('workspace.docker')) {
            if (workspace_docker_preview_active()) {
                $this->comingSoonPreview = true;
                $this->bootWorkspace($server);

                return;
            }

            abort(404);
        }

        $this->bootWorkspace($server);

        if (! $this->comingSoonPreview && $this->workspace_tab !== 'overview') {
            $this->loadTabIfNeeded($this->workspace_tab);
        }
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

    public function setWorkspaceTab(string $tab): void
    {
        $this->workspace_tab = in_array($tab, self::TABS, true) ? $tab : 'overview';
        $this->loadTabIfNeeded($this->workspace_tab);
    }

    public function loadContainers(): void
    {
        $this->loadRemoteList('containers');
    }

    public function loadImages(): void
    {
        $this->loadRemoteList('images');
    }

    public function loadVolumes(): void
    {
        $this->loadRemoteList('volumes');
    }

    public function loadNetworks(): void
    {
        $this->loadRemoteList('networks');
    }

    public function loadComposeProjects(): void
    {
        $this->loadRemoteList('compose');
    }

    public function loadSystemDiskUsage(): void
    {
        $this->loadRemoteList('maintenance');
    }

    public function confirmDockerContainerAction(string $actionKey, string $containerId): void
    {
        $allowed = [
            'docker_container_start',
            'docker_container_stop',
            'docker_container_restart',
            'docker_container_rm',
        ];

        if (! in_array($actionKey, $allowed, true)) {
            $this->toastError(__('Unknown action.'));

            return;
        }

        $this->openDockerManageAction($actionKey, [$actionKey, $containerId]);
    }

    public function openContainerExec(string $containerId, string $containerName): void
    {
        if (! app(ServerDockerRemoteInspector::class)->isValidContainerRef($containerId)) {
            $this->toastError(__('Invalid container.'));

            return;
        }

        $this->execModalContainerId = $containerId;
        $this->execModalContainerName = $containerName;
        $this->execModalCommand = '';
    }

    public function closeContainerExecModal(): void
    {
        $this->execModalContainerId = null;
        $this->execModalContainerName = null;
        $this->execModalCommand = '';
    }

    public function submitContainerExec(): void
    {
        if ($this->execModalContainerId === null) {
            return;
        }

        $command = trim($this->execModalCommand);
        $inspector = app(ServerDockerRemoteInspector::class);

        if (! $inspector->isValidExecCommand($command)) {
            $this->toastError(__('Enter a single-line command (max 4000 characters).'));

            return;
        }

        $def = config('server_manage.service_actions.docker_container_exec', []);
        $confirm = __('Run `:command` inside container `:name`? Output appears in the console banner.', [
            'command' => $command,
            'name' => $this->execModalContainerName,
        ]);

        $this->openConfirmActionModal(
            'runAllowlistedManageAction',
            ['docker_container_exec', $this->execModalContainerId, null, $command],
            (string) ($def['label'] ?? __('Run command in container')),
            $confirm,
            (string) ($def['label'] ?? __('Run command')),
            false,
        );

        $this->closeContainerExecModal();
    }

    public function openContainerShell(string $containerId, string $containerName): void
    {
        if (! app(ServerDockerRemoteInspector::class)->isValidContainerRef($containerId)) {
            $this->toastError(__('Invalid container.'));

            return;
        }

        $this->shellModalContainerId = $containerId;
        $this->shellModalContainerName = $containerName;
        $this->shellModalCommand = '';
        $this->shellModalError = null;
        $this->shellModalRunning = false;
        $this->shellModalHistory = [];
    }

    public function closeContainerShell(): void
    {
        $this->shellModalContainerId = null;
        $this->shellModalContainerName = null;
        $this->shellModalCommand = '';
        $this->shellModalError = null;
        $this->shellModalRunning = false;
        $this->shellModalHistory = [];
    }

    public function clearContainerShellHistory(): void
    {
        $this->shellModalHistory = [];
        $this->shellModalError = null;
    }

    public function insertContainerShellCommand(string $command): void
    {
        $this->shellModalCommand = $command;
    }

    public function runContainerShellQuickAction(int $index): void
    {
        $actions = DockerContainerShellSupport::quickActions();
        if (! isset($actions[$index])) {
            return;
        }

        $this->shellModalCommand = $actions[$index]['cmd'];
        $this->runContainerShellCommand();
    }

    public function runContainerShellCommand(): void
    {
        if ($this->shellModalContainerId === null) {
            return;
        }

        $this->authorize('update', $this->server);

        if ($this->currentUserIsDeployer()) {
            $this->shellModalError = __('Deployers cannot run shell commands on servers.');

            return;
        }

        if ($this->shellModalRunning) {
            $this->shellModalError = __('A command is already running. Wait for it to complete.');

            return;
        }

        if (! $this->serverOpsReady()) {
            $this->shellModalError = __('Provisioning and SSH must be ready before running commands.');

            return;
        }

        $cmd = trim($this->shellModalCommand);
        $inspector = app(ServerDockerRemoteInspector::class);

        if (! $inspector->isValidExecCommand($cmd)) {
            $this->shellModalError = __('Enter a single-line command (max 4000 characters).');

            return;
        }

        $this->shellModalError = null;
        $this->shellModalRunning = true;
        $startedAt = microtime(true);

        try {
            $ssh = app(SshConnectionFactory::class)->forServer($this->server);
            $remote = DockerContainerShellSupport::remoteExecCommand($this->shellModalContainerId, $cmd);
            [$out, $exit] = $ssh->execWithCallbackAndExit(
                $remote,
                static fn (string $chunk) => null,
                120,
            );

            $this->shellModalHistory[] = [
                'cmd' => $cmd,
                'out' => Str::limit($out, 16000, "\n… (output truncated)"),
                'exit' => $exit,
                'error' => null,
            ];

            $this->logContainerShellAudit($cmd, $exit, null, $startedAt);
        } catch (\Throwable $e) {
            $message = Str::limit($e->getMessage(), 200);
            $this->shellModalHistory[] = [
                'cmd' => $cmd,
                'out' => '',
                'exit' => null,
                'error' => $message,
            ];
            $this->logContainerShellAudit($cmd, null, $message, $startedAt);
        } finally {
            $this->shellModalRunning = false;
        }

        if (count($this->shellModalHistory) > 30) {
            $this->shellModalHistory = array_slice($this->shellModalHistory, -30);
        }

        $this->shellModalCommand = '';
        $this->dispatch('scroll-console-bottom');
    }

    protected function logContainerShellAudit(string $command, ?int $exit, ?string $error, float $startedAt): void
    {
        $organization = $this->server->organization;
        if ($organization === null) {
            return;
        }

        $duration = (int) round((microtime(true) - $startedAt) * 1000);
        $status = $error !== null ? 'failed' : ($exit === 0 ? 'success' : 'nonzero_exit');

        audit_log(
            $organization,
            auth()->user(),
            'server.docker.container_shell_command',
            $this->server,
            null,
            [
                'container_id' => $this->shellModalContainerId,
                'container_name' => $this->shellModalContainerName,
                'command' => Str::limit($command, 1000),
                'exit_code' => $exit,
                'status' => $status,
                'duration_ms' => $duration,
                'error' => $error !== null ? Str::limit($error, 500) : null,
            ],
        );
    }

    public function confirmDockerComposeAction(string $actionKey, string $project, string $config): void
    {
        $allowed = [
            'docker_compose_up',
            'docker_compose_down',
            'docker_compose_restart',
        ];

        if (! in_array($actionKey, $allowed, true)) {
            $this->toastError(__('Unknown action.'));

            return;
        }

        $inspector = app(ServerDockerRemoteInspector::class);
        $config = $inspector->primaryComposeConfigFile($config);

        if (! $inspector->isValidComposeProjectName($project) || ! $inspector->isValidComposeConfigPath($config)) {
            $this->toastError(__('Invalid compose project.'));

            return;
        }

        $def = config('server_manage.service_actions.'.$actionKey, []);
        if (! is_array($def)) {
            $this->toastError(__('Unknown action.'));

            return;
        }

        $confirm = str_replace(':project', $project, (string) ($def['confirm'] ?? ''));

        $this->openConfirmActionModal(
            'runAllowlistedManageAction',
            [$actionKey, null, null, null, $project, $config],
            (string) ($def['label'] ?? $actionKey),
            $confirm,
            (string) ($def['label'] ?? $actionKey),
            $actionKey === 'docker_compose_down',
        );
    }

    public function openComposeLogs(string $project, string $config): void
    {
        $inspector = app(ServerDockerRemoteInspector::class);
        $config = $inspector->primaryComposeConfigFile($config);

        if (! $inspector->isValidComposeProjectName($project) || ! $inspector->isValidComposeConfigPath($config)) {
            $this->toastError(__('Invalid compose project.'));

            return;
        }

        $this->composeLogsModalProject = $project;
        $this->composeLogsModalConfig = $config;
        $this->composeLogsModalContent = '';
        $this->composeLogsModalError = null;
        $this->composeLogsModalLoading = true;

        try {
            $result = $inspector->composeProjectLogs($this->server, $project, $config);
            $this->composeLogsModalContent = $result['logs'];
            $this->composeLogsModalError = $result['error'];
        } catch (\Throwable $e) {
            $this->composeLogsModalError = $e->getMessage();
        } finally {
            $this->composeLogsModalLoading = false;
        }
    }

    public function closeComposeLogsModal(): void
    {
        $this->composeLogsModalProject = null;
        $this->composeLogsModalConfig = null;
        $this->composeLogsModalContent = '';
        $this->composeLogsModalError = null;
        $this->composeLogsModalLoading = false;
    }

    public function confirmDockerImageAction(string $actionKey, string $imageRef): void
    {
        $allowed = ['docker_image_rm'];

        if (! in_array($actionKey, $allowed, true)) {
            $this->toastError(__('Unknown action.'));

            return;
        }

        $this->openDockerManageAction($actionKey, [$actionKey, null, $imageRef]);
    }

    public function confirmDockerInstall(): void
    {
        $this->openDockerManageAction('install_docker', ['install_docker']);
    }

    public function confirmDockerUpgrade(): void
    {
        $this->openDockerManageAction('repair_docker', ['repair_docker']);
    }

    public function confirmDockerImagePull(): void
    {
        $ref = trim($this->pullImageInput);
        if ($ref === '') {
            $this->toastError(__('Enter an image reference (e.g. nginx:alpine).'));

            return;
        }

        if (! app(ServerDockerRemoteInspector::class)->isValidImageRef($ref)) {
            $this->toastError(__('Invalid image reference.'));

            return;
        }

        $this->openDockerManageAction('docker_image_pull', ['docker_image_pull', null, $ref]);
    }

    public function confirmDockerImagePrune(): void
    {
        $this->openDockerManageAction('docker_image_prune', ['docker_image_prune']);
    }

    public function confirmDockerVolumePrune(): void
    {
        $this->openDockerManageAction('docker_volume_prune', ['docker_volume_prune']);
    }

    public function confirmDockerSystemPrune(): void
    {
        $this->openDockerManageAction('docker_system_prune', ['docker_system_prune']);
    }

    public function openContainerLogs(string $containerId, string $containerName): void
    {
        if (! app(ServerDockerRemoteInspector::class)->isValidContainerRef($containerId)) {
            $this->toastError(__('Invalid container.'));

            return;
        }

        $this->logsModalContainerId = $containerId;
        $this->logsModalContainerName = $containerName;
        $this->logsModalContent = '';
        $this->logsModalError = null;
        $this->logsModalLoading = true;

        try {
            $result = app(ServerDockerRemoteInspector::class)->containerLogs($this->server, $containerId);
            $this->logsModalContent = $result['logs'];
            $this->logsModalError = $result['error'];
        } catch (\Throwable $e) {
            $this->logsModalError = $e->getMessage();
        } finally {
            $this->logsModalLoading = false;
        }
    }

    public function closeContainerLogsModal(): void
    {
        $this->logsModalContainerId = null;
        $this->logsModalContainerName = null;
        $this->logsModalContent = '';
        $this->logsModalError = null;
        $this->logsModalLoading = false;
    }

    public function openContainerInspect(string $containerId, string $containerName): void
    {
        if (! app(ServerDockerRemoteInspector::class)->isValidContainerRef($containerId)) {
            $this->toastError(__('Invalid container.'));

            return;
        }

        $this->inspectModalContainerId = $containerId;
        $this->inspectModalContainerName = $containerName;
        $this->inspectModalContent = '';
        $this->inspectModalError = null;
        $this->inspectModalLoading = true;

        try {
            $result = app(ServerDockerRemoteInspector::class)->containerInspect($this->server, $containerId);
            $this->inspectModalContent = $result['inspect'];
            $this->inspectModalError = $result['error'];
        } catch (\Throwable $e) {
            $this->inspectModalError = $e->getMessage();
        } finally {
            $this->inspectModalLoading = false;
        }
    }

    public function closeContainerInspectModal(): void
    {
        $this->inspectModalContainerId = null;
        $this->inspectModalContainerName = null;
        $this->inspectModalContent = '';
        $this->inspectModalError = null;
        $this->inspectModalLoading = false;
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
        if (! in_array($status, ['finished', 'failed'], true)) {
            return;
        }

        if ($status === 'finished') {
            $flash = $payload['flash_success'] ?? null;
            if (is_string($flash) && $flash !== '') {
                $this->toastSuccess($flash);
            }
            $this->server->refresh();
            $this->invalidateActiveTabCache();
            $this->loadTabIfNeeded($this->workspace_tab, force: true);
        } else {
            $error = $payload['error'] ?? null;
            if (is_string($error) && $error !== '') {
                $this->toastError($error);
            }
        }

        Cache::forget(ServerManageRemoteSshJob::cacheKey($this->manageRemoteTaskId));
        $this->manageRemoteTaskId = null;
    }

    public function render(): View
    {
        if ($this->comingSoonPreview) {
            return view('livewire.servers.workspace-docker-preview');
        }

        if (! in_array($this->workspace_tab, self::TABS, true)) {
            $this->workspace_tab = 'overview';
        }

        $this->server->refresh();

        $serviceActions = config('server_manage.service_actions', []);
        $viewData = DockerWorkspaceViewData::for($this->server);

        $dockerConsoleRun = ConsoleAction::query()
            ->where('subject_type', $this->server->getMorphClass())
            ->where('subject_id', $this->server->id)
            ->where('kind', 'manage_action')
            ->whereNull('dismissed_at')
            ->orderByDesc('created_at')
            ->first();

        return view('livewire.servers.workspace-docker', [
            'server' => $this->server,
            'docker' => $viewData['docker'],
            'docker_present' => $viewData['docker_present'],
            'checkedAt' => $viewData['checked_at'],
            'opsReady' => $this->serverOpsReady(),
            'isDeployer' => $this->currentUserIsDeployer(),
            'deletionSummary' => $this->showRemoveServerModal
                ? ServerRemovalAdvisor::summary($this->server)
                : null,
            'dockerConsoleRun' => $dockerConsoleRun,
            'serviceActions' => $serviceActions,
            'shellSshCommand' => $this->shellModalContainerId !== null
                ? DockerContainerShellSupport::localInteractiveSshOneLiner($this->server, $this->shellModalContainerId)
                : '',
            'shellQuickActions' => DockerContainerShellSupport::quickActions(),
            'managedSites' => $viewData['managed_sites'],
        ]);
    }

    /**
     * @param  list<string|int|null>  $actionArgs
     */
    private function openDockerManageAction(string $key, array $actionArgs): void
    {
        $service = config('server_manage.service_actions', []);
        $def = $service[$key] ?? null;
        if (! is_array($def)) {
            $this->toastError(__('Unknown action.'));

            return;
        }

        $this->openConfirmActionModal(
            'runAllowlistedManageAction',
            $actionArgs,
            (string) ($def['label'] ?? $key),
            (string) ($def['confirm'] ?? ''),
            (string) ($def['label'] ?? $key),
            false,
        );
    }

    protected function forceExtendedInventoryProbe(): bool
    {
        return true;
    }

    private function loadTabIfNeeded(string $tab, bool $force = false): void
    {
        match ($tab) {
            'containers' => ($force || $this->containers === null) && ! $this->containersLoading ? $this->loadRemoteList('containers') : null,
            'images' => ($force || $this->images === null) && ! $this->imagesLoading ? $this->loadRemoteList('images') : null,
            'volumes' => ($force || $this->volumes === null) && ! $this->volumesLoading ? $this->loadRemoteList('volumes') : null,
            'networks' => ($force || $this->networks === null) && ! $this->networksLoading ? $this->loadRemoteList('networks') : null,
            'compose' => ($force || $this->composeProjects === null) && ! $this->composeLoading ? $this->loadRemoteList('compose') : null,
            'maintenance' => ($force || $this->systemDf === null) && ! $this->systemDfLoading ? $this->loadRemoteList('maintenance') : null,
            default => null,
        };
    }

    private function loadRemoteList(string $tab): void
    {
        if (! $this->serverOpsReady() || $this->currentUserIsDeployer()) {
            return;
        }

        $inspector = app(ServerDockerRemoteInspector::class);

        match ($tab) {
            'containers' => $this->withRemoteLoad(
                loading: 'containersLoading',
                error: 'containersError',
                callback: fn () => $inspector->listContainers($this->server),
                assign: fn (array $result) => $this->containers = $result['containers'],
                empty: fn () => $this->containers = [],
            ),
            'images' => $this->withRemoteLoad(
                loading: 'imagesLoading',
                error: 'imagesError',
                callback: fn () => $inspector->listImages($this->server),
                assign: fn (array $result) => $this->images = $result['images'],
                empty: fn () => $this->images = [],
            ),
            'volumes' => $this->withRemoteLoad(
                loading: 'volumesLoading',
                error: 'volumesError',
                callback: fn () => $inspector->listVolumes($this->server),
                assign: fn (array $result) => $this->volumes = $result['volumes'],
                empty: fn () => $this->volumes = [],
            ),
            'networks' => $this->withRemoteLoad(
                loading: 'networksLoading',
                error: 'networksError',
                callback: fn () => $inspector->listNetworks($this->server),
                assign: fn (array $result) => $this->networks = $result['networks'],
                empty: fn () => $this->networks = [],
            ),
            'compose' => $this->withRemoteLoad(
                loading: 'composeLoading',
                error: 'composeError',
                callback: fn () => $inspector->listComposeProjects($this->server),
                assign: fn (array $result) => $this->composeProjects = $result['projects'],
                empty: fn () => $this->composeProjects = [],
            ),
            'maintenance' => $this->withRemoteLoad(
                loading: 'systemDfLoading',
                error: 'systemDfError',
                callback: fn () => $inspector->systemDiskUsage($this->server),
                assign: fn (array $result) => $this->systemDf = $result['rows'],
                empty: fn () => $this->systemDf = [],
            ),
            default => null,
        };
    }

    /**
     * @param  callable(): array<string, mixed>  $callback
     * @param  callable(array<string, mixed>): void  $assign
     * @param  callable(): void  $empty
     */
    private function withRemoteLoad(string $loading, string $error, callable $callback, callable $assign, callable $empty): void
    {
        $this->{$loading} = true;
        $this->{$error} = null;

        try {
            $result = $callback();
            $assign($result);
            $this->{$error} = is_string($result['error'] ?? null) ? $result['error'] : null;
        } catch (\Throwable $e) {
            $empty();
            $this->{$error} = $e->getMessage();
        } finally {
            $this->{$loading} = false;
        }
    }

    private function invalidateActiveTabCache(): void
    {
        match ($this->workspace_tab) {
            'containers' => $this->containers = null,
            'images' => $this->images = null,
            'volumes' => $this->volumes = null,
            'networks' => $this->networks = null,
            'compose' => $this->composeProjects = null,
            'maintenance' => $this->systemDf = null,
            default => null,
        };
    }
}
