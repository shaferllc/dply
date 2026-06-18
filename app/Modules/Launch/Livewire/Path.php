<?php

namespace App\Modules\Launch\Livewire;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Path extends Component
{
    public string $path = 'serverless';

    public function mount(string $path): void
    {
        abort_unless(array_key_exists($path, $this->definitions()), 404);

        $this->path = $path;
    }

    /**
     * @return array{
     *     eyebrow: string,
     *     title: string,
     *     description: string,
     *     items: list<array{title: string, description: string}>
     * }
     */
    public function page(): array
    {
        return $this->definitions()[$this->path];
    }

    /**
     * @return array<string, array{
     *     eyebrow: string,
     *     title: string,
     *     description: string,
     *     items: list<array{title: string, description: string, href: string, cta: string, priority?: 'primary'|'secondary'}>,
     * }>
     */
    protected function definitions(): array
    {
        return [
            'serverless' => [
                'eyebrow' => __('Serverless'),
                'title' => __('Start with a serverless target'),
                'description' => __('Use this lane for function-style app execution instead of creating or connecting a machine.'),
                'items' => [
                    [
                        'title' => __('AWS Lambda'),
                        'description' => __('Use the Lambda and Bref-oriented path for PHP app deploys that run as serverless functions.'),
                        'href' => route('launches.serverless'),
                        'cta' => __('Plan Lambda path'),
                    ],
                    [
                        'title' => __('DigitalOcean Functions'),
                        'description' => __('Target a DigitalOcean Functions namespace with a function-native deployment flow.'),
                        'href' => route('launches.serverless'),
                        'cta' => __('Plan Functions path'),
                    ],
                ],
            ],
            'kubernetes' => [
                'eyebrow' => __('Kubernetes'),
                'title' => __('Start with a cluster-first setup'),
                'description' => __('Use this lane when the deployment target is a Kubernetes cluster, whether that is local or remote.'),
                'items' => [
                    [
                        'title' => __('DigitalOcean Kubernetes'),
                        'description' => __('Target a managed DigitalOcean Kubernetes cluster with cluster and namespace-aware setup.'),
                        'href' => route('launches.kubernetes'),
                        'cta' => __('Open Kubernetes lane'),
                    ],
                    [
                        'title' => __('AWS Kubernetes'),
                        'description' => __('Carry the same repo-first container runtime model into AWS-backed Kubernetes targets when the app needs a cloud cluster.'),
                        'href' => route('launches.cloud-network'),
                        'cta' => __('Open AWS cloud lane'),
                    ],
                    [
                        'title' => __('Managed Kubernetes (DOKS)'),
                        'description' => __('Register an existing DOKS cluster as a dply server, then add container apps to it.'),
                        'href' => route('servers.create', ['host_target' => 'kubernetes']),
                        'cta' => __('Open server wizard'),
                    ],
                ],
            ],
            'cloud-network' => [
                'eyebrow' => __('Cloud'),
                'title' => __('Start with a cloud deployment'),
                'description' => __('Use this lane for managed cloud networking, provider-aware container targets, and regional hosted application topologies.'),
                'items' => [
                    [
                        'title' => __('AWS Docker'),
                        'description' => __('Carry the same repo-first container runtime model into AWS-backed Docker workloads after validating them locally.'),
                        'href' => route('launches.cloud-network'),
                        'cta' => __('Open AWS lane'),
                    ],
                    [
                        'title' => __('AWS Kubernetes'),
                        'description' => __('Use the shared container platform story for AWS Kubernetes targets that need cluster-first configuration.'),
                        'href' => route('launches.cloud-network'),
                        'cta' => __('Open AWS Kubernetes lane'),
                    ],
                ],
            ],
        ];
    }

    public function render(): View
    {
        return view('livewire.launches.path', [
            'page' => $this->page(),
        ]);
    }
}
