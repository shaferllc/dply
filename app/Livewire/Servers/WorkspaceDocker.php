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
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Support\Servers\DockerWorkspaceViewData;
use App\Support\Servers\ServerDockerRemoteInspector;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
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

    public function confirmDockerImageAction(string $actionKey, string $imageRef): void
    {
        $allowed = ['docker_image_rm'];

        if (! in_array($actionKey, $allowed, true)) {
            $this->toastError(__('Unknown action.'));

            return;
        }

        $this->openDockerManageAction($actionKey, [$actionKey, null, $imageRef]);
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
