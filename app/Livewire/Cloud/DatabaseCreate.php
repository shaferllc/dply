<?php

declare(strict_types=1);

namespace App\Livewire\Cloud;

use App\Actions\Cloud\CreateCloudDatabase;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\CloudDatabase;
use App\Models\ProviderCredential;
use Illuminate\Contracts\View\View;
use Laravel\Pennant\Feature;
use Livewire\Component;

/**
 * Create flow for a managed database on the dply cloud platform.
 *
 * Mirrors {@see Create} (the container app create form): a single
 * card-shaped form that hands off to an Action, which creates the row
 * in STATUS_PROVISIONING and dispatches the provision job.
 */
class DatabaseCreate extends Component
{
    use DispatchesToastNotifications;

    public string $name = '';

    /** postgres | mysql | redis */
    public string $engine = CloudDatabase::ENGINE_POSTGRES;

    public string $version = '16';

    /** small | medium | large — keys of CloudDatabase::SIZE_TIERS. */
    public string $size = 'small';

    public string $region = 'nyc1';

    /**
     * Engine version options keyed by engine. DigitalOcean Managed
     * Databases supports these major versions at the time of writing.
     *
     * @var array<string, list<string>>
     */
    public const ENGINE_VERSIONS = [
        CloudDatabase::ENGINE_POSTGRES => ['16', '15', '14', '13'],
        CloudDatabase::ENGINE_MYSQL => ['8'],
        CloudDatabase::ENGINE_REDIS => ['7'],
    ];

    /**
     * DigitalOcean Managed Database datacenter regions. These use the
     * datacenter slugs (nyc1, ams3, …) the DO databases API expects —
     * distinct from the App Platform region slugs.
     *
     * @var list<array{slug: string, label: string}>
     */
    public const REGIONS = [
        ['slug' => 'nyc1', 'label' => 'New York 1 (US)'],
        ['slug' => 'nyc3', 'label' => 'New York 3 (US)'],
        ['slug' => 'sfo3', 'label' => 'San Francisco 3 (US)'],
        ['slug' => 'tor1', 'label' => 'Toronto 1 (CA)'],
        ['slug' => 'ams3', 'label' => 'Amsterdam 3 (NL)'],
        ['slug' => 'fra1', 'label' => 'Frankfurt 1 (DE)'],
        ['slug' => 'lon1', 'label' => 'London 1 (UK)'],
        ['slug' => 'sgp1', 'label' => 'Singapore 1 (SG)'],
        ['slug' => 'syd1', 'label' => 'Sydney 1 (AU)'],
        ['slug' => 'blr1', 'label' => 'Bangalore 1 (IN)'],
    ];

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'engine' => ['required', 'in:'.implode(',', array_keys(self::ENGINE_VERSIONS))],
            'version' => ['required', 'string', 'max:20'],
            'size' => ['required', 'in:'.implode(',', array_keys(CloudDatabase::SIZE_TIERS))],
            'region' => ['required', 'string', 'max:20'],
        ];
    }

    public function mount(): void
    {
        abort_unless(Feature::active('surface.cloud'), 404);
    }

    /**
     * Switching engine resets the version to that engine's newest
     * supported major so the version dropdown is never stale.
     */
    public function updatedEngine(string $value): void
    {
        $versions = self::ENGINE_VERSIONS[$value] ?? [];
        if ($versions !== [] && ! in_array($this->version, $versions, true)) {
            $this->version = $versions[0];
        }
    }

    public function create(): void
    {
        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            $this->toastError(__('Select or create an organization first.'));

            return;
        }

        $this->validate();

        try {
            (new CreateCloudDatabase)->handle($org, [
                'name' => $this->name,
                'engine' => $this->engine,
                'version' => $this->version,
                'size' => $this->size,
                'region' => $this->region,
            ]);
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->toastSuccess(__('Database provisioning. We\'ll mark it active once the cluster comes online.'));
        $this->redirect(route('cloud.databases.index'), navigate: true);
    }

    public function render(): View
    {
        $org = auth()->user()?->currentOrganization();
        $hasDoCredential = $org !== null && ProviderCredential::query()
            ->where('organization_id', $org->id)
            ->where('provider', 'digitalocean')
            ->exists();

        return view('livewire.cloud.database-create', [
            'engineVersions' => self::ENGINE_VERSIONS,
            'regions' => self::REGIONS,
            'sizeTiers' => CloudDatabase::SIZE_TIERS,
            'hasDoCredential' => $hasDoCredential,
        ])->layout('layouts.app');
    }
}
