<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Support\Servers\ServerDockerRemoteInspector;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait LoadsDockerResources
{


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
