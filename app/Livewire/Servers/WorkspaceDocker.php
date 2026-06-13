<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Jobs\ServerManageRemoteSshJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Servers\Concerns\DismissesServerConsoleActionRun;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\LoadsDockerResources;
use App\Livewire\Servers\Concerns\ManagesDockerComposeImages;
use App\Livewire\Servers\Concerns\ManagesDockerContainers;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use App\Livewire\Servers\Concerns\RunsAllowlistedManageAction;
use App\Livewire\Servers\Concerns\RunsServerInventoryProbe;
use App\Models\ConsoleAction;
use App\Models\Server;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Services\SshConnectionFactory;
use App\Support\Servers\DockerContainerShellSupport;
use App\Support\Servers\DockerWorkspaceViewData;
use App\Support\Servers\ServerDockerRemoteInspector;
use App\Support\Sites\SiteCreateAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
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
#[Lazy]
class WorkspaceDocker extends Component
{
    use RendersWorkspacePlaceholder;
    use RequiresFeature;

    protected string $requiredFeature = 'workspace.docker';

    /** When true, render the coming-soon teaser instead of the full workspace. */
    public bool $comingSoonPreview = false;

    use ConfirmsActionWithModal;
    use DismissesServerConsoleActionRun;
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;
    use LoadsDockerResources;
    use ManagesDockerComposeImages;
    use ManagesDockerContainers;
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

        $serviceActions = config('server_manage.service_actions', []);
        $viewData = DockerWorkspaceViewData::for($this->server);
        $siteCreateAccess = SiteCreateAccess::assess($this->server);

        $dockerConsoleRun = ConsoleAction::query()
            ->with('subject')
            ->where('subject_type', $this->server->getMorphClass())
            ->where('subject_id', $this->server->id)
            ->where('kind', 'manage_action')
            ->whereNull('dismissed_at')
            ->orderByDesc('created_at')
            ->first();

        return view('livewire.servers.workspace-docker', [
            'siteCreateBlockedReason' => $siteCreateAccess['blocked_reason'],
            'canCreateDockerSite' => $siteCreateAccess['can_create'],
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


    protected function forceExtendedInventoryProbe(): bool
    {
        return true;
    }


}
