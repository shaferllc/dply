<?php

namespace App\Livewire\Launches;

use App\Models\Server;
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
        $page = $this->definitions()[$this->path];

        if ($this->path === 'containers') {
            $page['existing_targets'] = $this->existingContainerTargets();
        }

        return $page;
    }

    /**
     * @return array<string, array{
     *     eyebrow: string,
     *     title: string,
     *     description: string,
     *     items: list<array{title: string, description: string, href: string, cta: string, priority?: 'primary'|'secondary'}>,
     *     existing_targets?: list<array{id: string, name: string, kind: string, href: string}>
     * }>
     */
    protected function definitions(): array
    {
        return [
            'containers' => [
                'eyebrow' => __('Containers'),
                'title' => __('Start with the shared container platform'),
                'description' => __('Use one repo-first container lane for local Docker, remote Docker, and remote Kubernetes targets.'),
                'items' => [
                    [
                        'title' => __('Local Docker'),
                        'description' => __('Inspect a repo, confirm the inferred runtime, and launch the first workload locally on your machine.'),
                        'href' => route('launches.local-docker'),
                        'cta' => __('Open local Docker'),
                        'priority' => 'primary',
                    ],
                    [
                        'title' => __('Remote Docker'),
                        'description' => __('Use the same inspected repo and continue into a remote Docker target on your own server or a cloud host.'),
                        'href' => route('launches.local-docker'),
                        'cta' => __('Open remote Docker'),
                    ],
                    [
                        'title' => __('Remote Kubernetes'),
                        'description' => __('Carry the same repo-first inspection into Kubernetes when the target needs a cluster instead of plain Docker.'),
                        'href' => route('launches.kubernetes'),
                        'cta' => __('Open Kubernetes lane'),
                    ],
                ],
            ],
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
                        'title' => __('Local Kubernetes'),
                        'description' => __('Use the same repo-first workflow when you want to test Kubernetes locally before moving to a remote cluster.'),
                        'href' => route('launches.local-docker'),
                        'cta' => __('Open local container lane'),
                    ],
                ],
            ],
            'edge-network' => [
                'eyebrow' => __('Edge network'),
                'title' => __('Start with an edge network deployment'),
                'description' => __('Use this lane for edge-runtime, static, and globally distributed delivery models.'),
                'items' => [
                    [
                        'title' => __('Edge runtime'),
                        'description' => __('Prepare edge-native execution flows for worker-style runtime targets.'),
                        'href' => route('launches.edge-network'),
                        'cta' => __('Open edge lane'),
                    ],
                    [
                        'title' => __('Static delivery'),
                        'description' => __('Prepare static and JS framework deploys that should run close to the network edge.'),
                        'href' => route('launches.edge-network'),
                        'cta' => __('Open static edge path'),
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

    /**
     * @return list<array{id: string, name: string, kind: string, href: string}>
     */
    protected function existingContainerTargets(): array
    {
        $organization = auth()->user()?->currentOrganization();

        if (! $organization) {
            return [];
        }

        return Server::query()
            ->where('organization_id', $organization->id)
            ->where(function ($query): void {
                $query
                    ->whereJsonContains('meta->host_kind', Server::HOST_KIND_DOCKER)
                    ->orWhereJsonContains('meta->host_kind', Server::HOST_KIND_KUBERNETES);
            })
            ->orderByDesc('created_at')
            ->limit(4)
            ->get()
            ->map(fn (Server $server): array => [
                'id' => (string) $server->id,
                'name' => $server->name,
                'kind' => $server->isDockerHost() ? __('Docker host') : __('Kubernetes cluster'),
                'href' => route('sites.create', $server),
            ])
            ->all();
    }

    public function render(): View
    {
        return view('livewire.launches.path', [
            'page' => $this->page(),
        ]);
    }
}
