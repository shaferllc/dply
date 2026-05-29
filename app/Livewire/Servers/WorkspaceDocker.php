<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Jobs\ServerManageRemoteSshJob;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Servers\Concerns\HandlesServerRemovalFlow;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\RunsAllowlistedManageAction;
use App\Models\Server;
use App\Services\Servers\ServerRemovalAdvisor;
use App\Support\Servers\DockerWorkspaceViewData;
use App\Support\Servers\ServerDockerRemoteInspector;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceDocker extends Component
{
    use RequiresFeature;

    protected string $requiredFeature = 'workspace.docker';

    use ConfirmsActionWithModal;
    use HandlesServerRemovalFlow;
    use InteractsWithServerWorkspace;
    use RunsAllowlistedManageAction;

    /** @var list<array{id: string, name: string, image: string, status: string, state: string}>|null */
    public ?array $containers = null;

    public ?string $containersError = null;

    public bool $containersLoading = false;

    /** @var list<array{id: string, repository: string, tag: string, size: string, created: string}>|null */
    public ?array $images = null;

    public ?string $imagesError = null;

    public bool $imagesLoading = false;

    #[Url(as: 'tab', except: 'overview', history: true)]
    public string $workspace_tab = 'overview';

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
    }

    public function setWorkspaceTab(string $tab): void
    {
        $allowed = ['overview', 'containers', 'images'];
        $this->workspace_tab = in_array($tab, $allowed, true) ? $tab : 'overview';

        if ($this->workspace_tab === 'containers' && $this->containers === null && ! $this->containersLoading) {
            $this->loadContainers();
        }

        if ($this->workspace_tab === 'images' && $this->images === null && ! $this->imagesLoading) {
            $this->loadImages();
        }
    }

    public function loadContainers(): void
    {
        if (! $this->serverOpsReady() || $this->currentUserIsDeployer()) {
            return;
        }

        $this->containersLoading = true;
        $this->containersError = null;

        try {
            $result = app(ServerDockerRemoteInspector::class)->listContainers($this->server);
            $this->containers = $result['containers'];
            $this->containersError = $result['error'];
        } catch (\Throwable $e) {
            $this->containers = [];
            $this->containersError = $e->getMessage();
        } finally {
            $this->containersLoading = false;
        }
    }

    public function loadImages(): void
    {
        if (! $this->serverOpsReady() || $this->currentUserIsDeployer()) {
            return;
        }

        $this->imagesLoading = true;
        $this->imagesError = null;

        try {
            $result = app(ServerDockerRemoteInspector::class)->listImages($this->server);
            $this->images = $result['images'];
            $this->imagesError = $result['error'];
        } catch (\Throwable $e) {
            $this->images = [];
            $this->imagesError = $e->getMessage();
        } finally {
            $this->imagesLoading = false;
        }
    }

    public function confirmDockerContainerAction(string $actionKey, string $containerId): void
    {
        $allowed = [
            'docker_container_start' => 'docker_container_start',
            'docker_container_stop' => 'docker_container_stop',
            'docker_container_restart' => 'docker_container_restart',
        ];

        if (! isset($allowed[$actionKey])) {
            $this->toastError(__('Unknown action.'));

            return;
        }

        $service = config('server_manage.service_actions', []);
        $def = $service[$allowed[$actionKey]] ?? null;
        if (! is_array($def)) {
            $this->toastError(__('Unknown action.'));

            return;
        }

        $this->openConfirmActionModal(
            'runAllowlistedManageAction',
            [$allowed[$actionKey], $containerId],
            (string) ($def['label'] ?? $actionKey),
            (string) ($def['confirm'] ?? ''),
            (string) ($def['label'] ?? $actionKey),
            false,
        );
    }

    public function confirmDockerImagePrune(): void
    {
        $def = config('server_manage.service_actions.docker_image_prune', []);
        if (! is_array($def)) {
            $this->toastError(__('Unknown action.'));

            return;
        }

        $this->openConfirmActionModal(
            'runAllowlistedManageAction',
            ['docker_image_prune'],
            (string) ($def['label'] ?? 'Prune unused images'),
            (string) ($def['confirm'] ?? ''),
            (string) ($def['label'] ?? 'Prune unused images'),
            false,
        );
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
            if ($this->workspace_tab === 'containers') {
                $this->containers = null;
                $this->loadContainers();
            }
            if ($this->workspace_tab === 'images') {
                $this->images = null;
                $this->loadImages();
            }
        }

        Cache::forget(ServerManageRemoteSshJob::cacheKey($this->manageRemoteTaskId));
        $this->manageRemoteTaskId = null;
    }

    public function render(): View
    {
        if (! in_array($this->workspace_tab, ['overview', 'containers', 'images'], true)) {
            $this->workspace_tab = 'overview';
        }

        $this->server->refresh();

        $serviceActions = config('server_manage.service_actions', []);

        $viewData = DockerWorkspaceViewData::for($this->server);

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
            'pruneAction' => is_array($serviceActions['docker_image_prune'] ?? null)
                ? $serviceActions['docker_image_prune']
                : null,
        ]);
    }
}
