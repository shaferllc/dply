<?php

namespace App\Livewire\Launches;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Create extends Component
{
    /**
     * @return list<array{title: string, description: string}>
     */
    public function launchOptions(): array
    {
        return [
            [
                'title' => __('Bring your own server'),
                'description' => __('Connect a customer-owned machine over SSH and manage it as a real BYO host.'),
            ],
            [
                'title' => __('Containers'),
                'description' => __('Start from a repo-first container lane that covers local Docker, remote Docker, and remote Kubernetes targets.'),
            ],
            [
                'title' => __('Edge'),
                'description' => __('Route edge-runtime and static-network deploys through a dedicated edge path.'),
            ],
            [
                'title' => __('Cloud'),
                'description' => __('Start managed cloud network deploys from a network-aware setup flow.'),
            ],
            [
                'title' => __('Serverless'),
                'description' => __('Launch function-based app targets without modeling them as traditional servers.'),
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
