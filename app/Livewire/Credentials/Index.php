<?php

namespace App\Livewire\Credentials;

use App\Livewire\Concerns\ManagesProviderCredentials;
use App\Models\ProviderCredential;
use App\Support\ServerProviderGate;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.settings')]
class Index extends Component
{
    use ManagesProviderCredentials;

    /** @var string Provider key from {@see credentialProviderNav()} */
    public string $active_provider = 'digitalocean';

    public function mount(): void
    {
        $this->authorize('viewAny', ProviderCredential::class);

        $ids = self::credentialProviderIds();
        if ($ids !== [] && ! in_array($this->active_provider, $ids, true)) {
            $this->active_provider = $ids[0];
        }

        $q = request()->query('provider');
        if (is_string($q) && ServerProviderGate::enabled($q) && in_array($q, $ids, true)) {
            $this->active_provider = $q;
        }
    }

    public function updatedActiveProvider(mixed $value): void
    {
        $ids = self::credentialProviderIds();
        if (! is_string($value) || ! in_array($value, $ids, true)) {
            $this->active_provider = $ids[0] ?? 'digitalocean';
        }
    }

    /**
     * Sidebar groups for the provider picker (IDs match `provider_credentials.provider` where applicable).
     *
     * @return list<array{label: string, items: list<array{id: string, label: string}>}>
     */
    public static function credentialProviderNav(): array
    {
        $groups = [
            [
                'label' => __('VPS & cloud'),
                'items' => [
                    ['id' => 'digitalocean', 'label' => 'DigitalOcean'],
                    ['id' => 'hetzner', 'label' => 'Hetzner'],
                    ['id' => 'linode', 'label' => 'Linode'],
                    ['id' => 'vultr', 'label' => 'Vultr'],
                    ['id' => 'akamai', 'label' => __('Akamai (Linode API)')],
                ],
            ],
            [
                'label' => __('Infrastructure'),
                'items' => [
                    ['id' => 'equinix_metal', 'label' => 'Equinix Metal'],
                    ['id' => 'upcloud', 'label' => 'UpCloud'],
                    ['id' => 'scaleway', 'label' => 'Scaleway'],
                    ['id' => 'ovh', 'label' => __('OVH Public Cloud')],
                    ['id' => 'rackspace', 'label' => __('Rackspace (OpenStack)')],
                ],
            ],
            [
                'label' => __('Platforms'),
                'items' => [
                    ['id' => 'fly_io', 'label' => 'Fly.io'],
                    ['id' => 'render', 'label' => 'Render'],
                    ['id' => 'railway', 'label' => 'Railway'],
                    ['id' => 'coolify', 'label' => 'Coolify'],
                    ['id' => 'cap_rover', 'label' => 'CapRover'],
                ],
            ],
            [
                'label' => __('Hyperscale'),
                'items' => [
                    ['id' => 'aws', 'label' => 'AWS'],
                    ['id' => 'gcp', 'label' => 'Google Cloud'],
                    ['id' => 'azure', 'label' => 'Azure'],
                    ['id' => 'oracle', 'label' => __('Oracle Cloud')],
                ],
            ],
        ];

        $filtered = [];
        foreach ($groups as $group) {
            $items = array_values(array_filter(
                $group['items'],
                static fn (array $item) => ServerProviderGate::enabled($item['id'])
            ));
            if ($items !== []) {
                $filtered[] = [
                    'label' => $group['label'],
                    'items' => $items,
                ];
            }
        }

        return $filtered;
    }

    /**
     * @return list<string>
     */
    public static function credentialProviderIds(): array
    {
        $ids = [];
        foreach (self::credentialProviderNav() as $group) {
            foreach ($group['items'] as $item) {
                $ids[] = $item['id'];
            }
        }

        return $ids;
    }

    public function resolveActiveProviderLabel(): string
    {
        foreach (self::credentialProviderNav() as $group) {
            foreach ($group['items'] as $item) {
                if ($item['id'] === $this->active_provider) {
                    return $item['label'];
                }
            }
        }

        return $this->active_provider;
    }

    public function credentialCountFor(string $provider): int
    {
        $org = auth()->user()->currentOrganization();
        $query = $org
            ? ProviderCredential::query()->where('organization_id', $org->id)
            : auth()->user()->providerCredentials()->whereNull('organization_id');

        return (int) $query->where('provider', $provider)->count();
    }

    public function render(): View
    {
        $org = auth()->user()->currentOrganization();
        $credentials = $org
            ? ProviderCredential::where('organization_id', $org->id)->latest()->get()
            : auth()->user()->providerCredentials()->whereNull('organization_id')->latest()->get();

        return view('livewire.credentials.index', [
            'credentials' => $credentials,
            'providerNav' => self::credentialProviderNav(),
            'activeProviderLabel' => $this->resolveActiveProviderLabel(),
        ]);
    }
}
