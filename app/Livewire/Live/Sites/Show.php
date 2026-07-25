<?php

declare(strict_types=1);

namespace App\Livewire\Live\Sites;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Live\Concerns\ConfirmsProductionWrites;
use App\Livewire\Live\Concerns\InteractsWithProductionData;
use App\Services\ProductionData\ProductionApiException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class Show extends Component
{
    use ConfirmsProductionWrites;
    use DispatchesToastNotifications;
    use InteractsWithProductionData;

    public string $remoteSiteId = '';

    #[Url(as: 'tab', except: 'overview')]
    public string $tab = 'overview';

    public string $envContent = '';

    public bool $envLoaded = false;

    public ?string $watchingDeploymentId = null;

    public ?array $watchingDeployment = null;

    /** @var list<string> */
    public const TABS = [
        'overview',
        'deployments',
        'env',
        'domains',
        'workers',
        'schedules',
        'ssl',
        'databases',
        'errors',
        'uptime',
    ];

    /** @var list<string> */
    public const IMPLEMENTED_TABS = ['overview', 'deployments', 'env'];

    public function mount(string $remoteSite): void
    {
        if ($this->requireProductionConnection() === null) {
            return;
        }

        $this->remoteSiteId = $remoteSite;

        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'overview';
        }
    }

    public function setTab(string $tab): void
    {
        if (! in_array($tab, self::TABS, true)) {
            return;
        }

        $this->tab = $tab;

        if ($tab === 'env' && ! $this->envLoaded) {
            $this->loadEnv();
        }
    }

    public function refresh(): void
    {
        $connection = $this->requireProductionConnection();
        if ($connection === null) {
            return;
        }

        $mirror = $this->productionMirror();
        $mirror->forget($connection, 'site:'.$this->remoteSiteId);
        $mirror->forget($connection, 'site:'.$this->remoteSiteId.':deployments');
        $mirror->forget($connection, 'site:'.$this->remoteSiteId.':env');
        $this->envLoaded = false;
        if ($this->tab === 'env') {
            $this->loadEnv();
        }
        $this->toastSuccess(__('Refreshed from production.'));
    }

    public function requestDeploy(): void
    {
        $this->runProductionWrite(
            'performDeploy',
            [],
            __('Deploy on production'),
            __('Queue a deploy for this live site. Type PRODUCTION to allow writes for this session.'),
        );
    }

    public function performDeploy(): void
    {
        $connection = $this->requireProductionConnection();
        if ($connection === null) {
            return;
        }

        try {
            $this->productionMirror()->withClient($connection, function ($client) {
                $client->deploy($this->remoteSiteId);
            });
            $this->productionMirror()->forget($connection, 'site:'.$this->remoteSiteId.':deployments');
            $this->productionMirror()->forget($connection, 'site:'.$this->remoteSiteId);

            $deployments = $this->productionMirror()->withClient(
                $connection,
                fn ($client) => $client->deployments($this->remoteSiteId, 1),
            );
            $latest = $deployments[0] ?? null;
            if (is_array($latest) && isset($latest['id'])) {
                $this->watchingDeploymentId = (string) $latest['id'];
                $this->watchingDeployment = $latest;
                $this->tab = 'deployments';
            }

            $this->toastSuccess(__('Deploy queued on production.'));
        } catch (ProductionApiException $e) {
            $this->handleProductionApiError($e);
        }
    }

    public function pollWatchingDeployment(): void
    {
        if ($this->watchingDeploymentId === null) {
            return;
        }

        $connection = $this->productionConnection;
        if ($connection === null) {
            return;
        }

        try {
            $deployment = $this->productionMirror()->withClient(
                $connection,
                fn ($client) => $client->deployment($this->remoteSiteId, $this->watchingDeploymentId),
            );
            $this->watchingDeployment = $deployment;
            $status = (string) ($deployment['status'] ?? '');
            if (in_array($status, ['success', 'failed', 'skipped'], true)
                || (($deployment['finished_at'] ?? null) !== null)) {
                $this->watchingDeploymentId = null;
                $this->productionMirror()->forget($connection, 'site:'.$this->remoteSiteId.':deployments');
            }
        } catch (ProductionApiException $e) {
            $this->handleProductionApiError($e);
        }
    }

    public function loadEnv(): void
    {
        $connection = $this->requireProductionConnection();
        if ($connection === null) {
            return;
        }

        try {
            $this->envContent = $this->productionMirror()->remember(
                $connection,
                'site:'.$this->remoteSiteId.':env',
                fn ($client) => $client->envContent($this->remoteSiteId),
            );
            $this->envLoaded = true;
        } catch (ProductionApiException $e) {
            $this->handleProductionApiError($e);
        }
    }

    public function requestSaveEnv(): void
    {
        $this->runProductionWrite(
            'performSaveEnv',
            [],
            __('Save production env'),
            __('Overwrite the live site .env cache. Type PRODUCTION to allow writes for this session.'),
        );
    }

    public function performSaveEnv(): void
    {
        $connection = $this->requireProductionConnection();
        if ($connection === null) {
            return;
        }

        try {
            $this->productionMirror()->withClient($connection, function ($client) {
                $client->putEnvContent($this->remoteSiteId, $this->envContent);
            });
            $this->productionMirror()->forget($connection, 'site:'.$this->remoteSiteId.':env');
            $this->toastSuccess(__('Production env saved.'));
        } catch (ProductionApiException $e) {
            $this->handleProductionApiError($e);
        }
    }

    public function render(): View
    {
        $connection = $this->requireProductionConnection();
        if ($connection === null) {
            return view('livewire.live.sites.show', [
                'connection' => new \App\Models\ProductionDataConnection(['base_url' => '']),
                'site' => [],
                'deployments' => [],
                'error' => null,
                'implementedTabs' => self::IMPLEMENTED_TABS,
                'allTabs' => self::TABS,
                'writesUnlocked' => false,
            ]);
        }

        $site = [];
        $deployments = [];
        $error = null;

        try {
            $site = $this->productionMirror()->remember(
                $connection,
                'site:'.$this->remoteSiteId,
                fn ($client) => $client->site($this->remoteSiteId),
            );

            if ($this->tab === 'deployments' || $this->watchingDeploymentId !== null) {
                $deployments = $this->productionMirror()->remember(
                    $connection,
                    'site:'.$this->remoteSiteId.':deployments',
                    fn ($client) => $client->deployments($this->remoteSiteId, 20),
                );
            }
        } catch (ProductionApiException $e) {
            if ($e->isUnauthorized()) {
                $this->handleProductionApiError($e);
            } else {
                $error = $e->getMessage();
            }
        }

        return view('livewire.live.sites.show', [
            'connection' => $connection,
            'site' => $site,
            'deployments' => $deployments,
            'error' => $error,
            'implementedTabs' => self::IMPLEMENTED_TABS,
            'allTabs' => self::TABS,
            'writesUnlocked' => $this->productionMirror()->writesUnlocked(),
        ]);
    }
}
