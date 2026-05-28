<?php

namespace App\Livewire\Launches;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Create extends Component
{
    public function mount(): void
    {
        // Launchpad is the multi-surface chooser; with VM as the only
        // active surface, the chooser collapses to a single option and the
        // /servers/create wizard is the obvious entry point. Re-enable
        // automatically the moment Cloud/Edge/Serverless light up.
        abort_unless(multi_surface_active(), 404);
    }

    /**
     * @return list<array{id: string, title: string, description: string, enabled: bool, icon: string, href?: string, featured?: bool}>
     */
    public function launchOptions(): array
    {
        $options = [];

        if (full_stack_wizard_active()) {
            $options[] = [
                'id' => 'full-stack',
                'title' => __('Full-stack from one repo'),
                'description' => __('Analyze a monorepo and split it across Edge, Cloud, and BYO with wiring guidance.'),
                'enabled' => true,
                'featured' => true,
                'href' => route('launches.full-stack'),
                'icon' => 'squares-2x2',
            ];
        }

        if (standby_blueprint_active()) {
            $options[] = [
                'id' => 'standby-blueprint',
                'title' => __('Standby blueprints'),
                'description' => __('Failover playbooks for hybrid Edge origins, BYO standby servers, and DNS cutover.'),
                'enabled' => true,
                'featured' => true,
                'href' => route('launches.standby'),
                'icon' => 'shield-check',
            ];
        }

        return array_merge($options, [
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
        ]);
    }

    public function render(): View
    {
        return view('livewire.launches.create', [
            'launchOptions' => $this->launchOptions(),
        ]);
    }
}
