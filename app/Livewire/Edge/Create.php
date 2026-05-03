<?php

declare(strict_types=1);

namespace App\Livewire\Edge;

use App\Actions\Edge\CreateEdgeSite;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\ProviderCredential;
use App\Services\Edge\AwsAppRunnerBackend;
use App\Services\Edge\DigitalOceanAppPlatformBackend;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Container app create flow for the dply edge platform — the
 * "deploy a container globally" UX, replacing the old "Connect
 * Fly.io" upsell with our own primary surface.
 */
class Create extends Component
{
    use DispatchesToastNotifications;

    #[Url]
    public string $backend = 'auto';

    public string $name = '';

    public string $image = '';

    public int $port = 8080;

    public string $region = '';

    public string $env_file_content = '';

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'image' => ['required', 'string', 'max:500'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'region' => ['required', 'string', 'max:50'],
            'backend' => ['required', 'in:auto,digitalocean_app_platform,aws_app_runner'],
            'env_file_content' => ['nullable', 'string', 'max:20000'],
        ];
    }

    public function mount(): void
    {
        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            $this->toastError(__('Select or create an organization first.'));

            return;
        }

        // Default region tied to the picked backend.
        $this->updatedBackend($this->backend);
    }

    public function updatedBackend(string $value): void
    {
        $regions = $this->backendRegions($value);
        if ($regions !== [] && ($this->region === '' || ! in_array($this->region, array_column($regions, 'slug'), true))) {
            $this->region = $regions[0]['slug'];
        }
    }

    public function deploy(): void
    {
        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            $this->toastError(__('Select or create an organization first.'));

            return;
        }

        $this->validate();

        try {
            $site = (new CreateEdgeSite)->handle(auth()->user(), $org, [
                'name' => $this->name,
                'image' => $this->image,
                'port' => $this->port,
                'region' => $this->region,
                'backend' => $this->backend,
                'env_file_content' => $this->env_file_content,
            ]);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->toastSuccess(__('Container app provisioning. We\'ll keep this page updated as it comes online.'));
        $this->redirect(route('sites.show', ['server' => $site->server, 'site' => $site]), navigate: true);
    }

    /**
     * @return list<array{slug: string, label: string}>
     */
    private function backendRegions(string $backend): array
    {
        return match ($backend) {
            'digitalocean_app_platform' => DigitalOceanAppPlatformBackend::class === '' ? [] : (new DigitalOceanAppPlatformBackend)->regions(),
            'aws_app_runner' => (new AwsAppRunnerBackend)->regions(),
            default => $this->mergedRegions(),
        };
    }

    /**
     * @return list<array{slug: string, label: string}>
     */
    private function mergedRegions(): array
    {
        $merged = [];
        foreach ((new DigitalOceanAppPlatformBackend)->regions() as $r) {
            $merged[$r['slug']] = ['slug' => $r['slug'], 'label' => 'DO · '.$r['label']];
        }
        foreach ((new AwsAppRunnerBackend)->regions() as $r) {
            $merged[$r['slug']] = ['slug' => $r['slug'], 'label' => 'AWS · '.$r['label']];
        }

        return array_values($merged);
    }

    public function render(): View
    {
        $org = auth()->user()?->currentOrganization();
        $connected = $org === null ? collect() : ProviderCredential::query()
            ->where('organization_id', $org->id)
            ->whereIn('provider', ['digitalocean_app_platform', 'aws_app_runner'])
            ->get(['id', 'provider', 'name']);

        return view('livewire.edge.create', [
            'connectedBackends' => $connected,
            'regions' => $this->backendRegions($this->backend),
        ])->layout('layouts.app');
    }
}
