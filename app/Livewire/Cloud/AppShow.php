<?php

namespace App\Livewire\Cloud;

use App\Models\Cloud\CloudApp;
use App\Models\Cloud\CloudCluster;
use App\Services\Cloud\RuntimeDetector;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Livewire component for viewing a dply Cloud application.
 */
class AppShow extends Component
{
    public CloudCluster $cluster;
    public CloudApp $app;

    public bool $showEnvEditor = false;

    public function mount(CloudCluster $cluster, CloudApp $app): void
    {
        $this->cluster = $cluster;
        $this->app = $app;
    }

    /**
     * Get the latest deploy.
     */
    #[Computed]
    public function latestDeploy(): ?\App\Models\Cloud\CloudDeploy
    {
        return $this->app->latestDeploy();
    }

    /**
     * Get environment variables as key-value pairs.
     */
    #[Computed]
    public function envVars(): array
    {
        return $this->app->envVarsArray();
    }

    /**
     * Get all domains.
     */
    #[Computed]
    public function domains(): array
    {
        return $this->app->allDomains();
    }

    public function render()
    {
        return view('livewire.cloud.app-show');
    }
}
