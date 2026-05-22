<?php

namespace App\Livewire\Launches;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Create extends Component
{
    /**
     * @return list<array{id: string, title: string, description: string, enabled: bool, icon: string, href?: string}>
     */
    public function launchOptions(): array
    {
        return [
            [
                'id' => 'byo',
                'title' => __('Bring your own server'),
                'description' => __('Connect a customer-owned machine over SSH and manage it as a real BYO host.'),
                'enabled' => true,
                'href' => route('servers.create'),
                'icon' => 'server',
            ],
            [
                'id' => 'containers',
                'title' => __('Run a container app'),
                'description' => __('Dply provisions a Docker host first, then you point it at your repo. Pick "Managed Kubernetes" inside the wizard to register a DOKS cluster instead.'),
                'enabled' => true,
                'href' => route('servers.create', ['host_target' => 'docker']),
                'icon' => 'cube',
            ],
            [
                'id' => 'cloud',
                'title' => __('Cloud'),
                'description' => __('Deploy a container image straight onto the dply Cloud platform — DO App Platform or AWS App Runner.'),
                'enabled' => true,
                'href' => route('cloud.create'),
                'icon' => 'cloud',
            ],
            [
                'id' => 'cloud-network',
                'title' => __('Cloud Network'),
                'description' => __('Start managed cloud network deploys from a network-aware setup flow.'),
                'enabled' => false,
                'icon' => 'globe-alt',
            ],
            [
                'id' => 'serverless',
                'title' => __('Serverless'),
                'description' => __('Launch function-based app targets without modeling them as traditional servers.'),
                'enabled' => true,
                'href' => route('serverless.create'),
                'icon' => 'sparkles',
            ],
        ];
    }

    public function render(): View
    {
        return view('livewire.launches.create', [
            'launchOptions' => $this->launchOptions(),
        ]);
    }
}
