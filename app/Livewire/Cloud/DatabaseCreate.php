<?php

declare(strict_types=1);

namespace App\Livewire\Cloud;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\CloudDatabase;
use App\Models\ProviderCredential;
use App\Modules\Cloud\Actions\CreateCloudDatabase;
use App\Modules\Providers\Services\DigitalOceanService;
use App\Support\Servers\ManagedDatabaseSizeCatalog;
use App\Support\Servers\ProviderManagedDatabaseRegion;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Laravel\Pennant\Feature;
use Livewire\Component;

/**
 * Create flow for a managed database on the dply cloud platform.
 *
 * Mirrors {@see Create} (the container app create form): a single
 * card-shaped form that hands off to an Action, which creates the row
 * in STATUS_PROVISIONING and dispatches the provision job.
 *
 * Engine versions, regions, and sizes always come from DigitalOcean's
 * live /v2/databases/options catalog — those lists change often.
 */
class DatabaseCreate extends Component
{
    use DispatchesToastNotifications;

    public string $name = '';

    /** postgres | mysql | redis */
    public string $engine = CloudDatabase::ENGINE_POSTGRES;

    public string $version = '';

    public string $size = '';

    public string $region = '';

    /**
     * Engines offered here.
     *
     * Redis is deliberately absent. A managed Redis IS a cache, and it now has
     * a product surface of its own at /caches — offering it from two places
     * would ship exactly the discoverability failure
     * docs/adr/managed-services-tier.md was written to fix, this time
     * knowingly. Existing `engine=redis` rows keep working and are adopted by
     * a ManagedCache; see docs/adr/dply-cache.md, decision 10.
     *
     * @var list<string>
     */
    private const ENGINES = [
        CloudDatabase::ENGINE_POSTGRES,
        CloudDatabase::ENGINE_MYSQL,
    ];

    public function mount(): void
    {
        abort_unless(Feature::active('surface.cloud'), 404);
        $this->syncCatalogDefaults();
    }

    /**
     * Switching engine resets version / region / size to that engine's
     * live catalog so the dropdowns are never stale.
     */
    public function updatedEngine(string $value): void
    {
        $this->engine = $value;
        $this->syncCatalogDefaults(force: true);
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

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $catalog = $this->liveCatalog();

        $versionRule = ['required', 'string', 'max:20'];
        if ($catalog['versions'] !== []) {
            $versionRule[] = Rule::in($catalog['versions']);
        }

        $sizeRule = ['required', 'string', 'max:40'];
        if ($catalog['sizes'] !== []) {
            $sizeRule[] = Rule::in(array_column($catalog['sizes'], 'value'));
        }

        $regionRule = ['required', 'string', 'max:20'];
        if ($catalog['regions'] !== []) {
            $regionRule[] = Rule::in(array_column($catalog['regions'], 'value'));
        }

        return [
            'name' => ['required', 'string', 'max:80'],
            'engine' => ['required', 'in:'.implode(',', self::ENGINES)],
            'version' => $versionRule,
            'size' => $sizeRule,
            'region' => $regionRule,
        ];
    }

    public function render(): View
    {
        $org = auth()->user()?->currentOrganization();
        $hasDoCredential = $org !== null && $this->digitalOceanCredential() !== null;
        $catalog = $this->liveCatalog();

        return view('livewire.cloud.database-create', [
            'engineVersions' => [$this->engine => $catalog['versions']],
            'regions' => $catalog['regions'],
            'sizeTiers' => $catalog['sizes'],
            'catalogError' => $catalog['error'],
            'hasDoCredential' => $hasDoCredential,
        ])->layout('layouts.app');
    }

    private function syncCatalogDefaults(bool $force = false): void
    {
        $catalog = $this->liveCatalog();

        if ($force || $this->version === '' || ($catalog['versions'] !== [] && ! in_array($this->version, $catalog['versions'], true))) {
            $this->version = $catalog['versions'][0] ?? '';
        }

        $sizeSlugs = array_column($catalog['sizes'], 'value');
        if ($force || $this->size === '' || ($sizeSlugs !== [] && ! in_array($this->size, $sizeSlugs, true))) {
            $this->size = $sizeSlugs[0] ?? '';
        }

        $regionSlugs = array_column($catalog['regions'], 'value');
        if ($force || $this->region === '' || ($regionSlugs !== [] && ! in_array($this->region, $regionSlugs, true))) {
            $this->region = $regionSlugs[0] ?? '';
        }
    }

    /**
     * @return array{versions: list<string>, regions: list<array{value: string, label: string}>, sizes: list<array{value: string, label: string, group: string}>, error: ?string}
     */
    private function liveCatalog(): array
    {
        $empty = [
            'versions' => [],
            'regions' => [],
            'sizes' => [],
            'error' => null,
        ];

        $credential = $this->digitalOceanCredential();
        if ($credential === null) {
            return $empty;
        }

        try {
            $service = new DigitalOceanService($credential);
            $versions = $service->getDatabaseEngineVersions($this->engine);
            $regionSlugs = $service->getDatabaseEngineRegions($this->engine);
            $sizeSlugs = $service->getDatabaseEngineSizes($this->engine);
        } catch (\Throwable $e) {
            return [
                ...$empty,
                'error' => $e->getMessage(),
            ];
        }

        $error = null;
        if ($regionSlugs === [] && $sizeSlugs === []) {
            $error = __('Could not load DigitalOcean\'s database catalog. Reconnect the credential and try again.');
        }

        return [
            'versions' => $versions,
            'regions' => ProviderManagedDatabaseRegion::options('digitalocean', $regionSlugs),
            'sizes' => ManagedDatabaseSizeCatalog::optionsFromSlugs($sizeSlugs),
            'error' => $error,
        ];
    }

    private function digitalOceanCredential(): ?ProviderCredential
    {
        $org = auth()->user()?->currentOrganization();
        if ($org === null) {
            return null;
        }

        return ProviderCredential::query()
            ->where('organization_id', $org->id)
            ->where('provider', 'digitalocean')
            ->orderBy('created_at')
            ->first();
    }
}
