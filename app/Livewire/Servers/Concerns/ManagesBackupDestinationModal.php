<?php

namespace App\Livewire\Servers\Concerns;

use App\Livewire\Concerns\AuthorsBackupDestinations;
use App\Models\BackupConfiguration;
use App\Models\ObjectStorageCredential;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Modules\Cloud\Services\DigitalOceanService;
use App\Services\Storage\ObjectStorageBucketProvisioner;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Reusable "Add backup destination" modal for any server workspace component.
 * Holds the modal state plus both create modes — "connect existing" (paste
 * credentials for an existing bucket) and "provision" (create a brand-new
 * bucket on a provider) — so every surface that needs a {@see BackupConfiguration}
 * (Backups, Snapshots → Cache, …) opens the identical dialog rather than
 * bouncing the operator to the Backups page.
 *
 * Pairs with `livewire.servers.partials.backups._add-destination-modal`.
 *
 * Hosts must extend {@see Component}, expose `$this->server`
 * (via {@see InteractsWithServerWorkspace}), and provide `toastSuccess`/
 * `toastError`. Override {@see onBackupDestinationCreated()} to react to a
 * freshly-created destination (e.g. auto-select it on a form).
 *
 * @phpstan-require-extends Component
 *
 * @property Server $server
 */
trait ManagesBackupDestinationModal
{
    use AuthorsBackupDestinations;

    public bool $showDestinationModal = false;

    /** @var array<string, mixed> */
    public array $destinationForm = [];

    /**
     * Add-destination modal mode:
     *   'connect'  — paste credentials for an existing bucket (any provider).
     *   'provision' — create a brand-new bucket on a provider (DO Spaces /
     *                 Hetzner) via ObjectStorageBucketProvisioner, then wire it.
     */
    public string $destination_create_mode = 'connect';

    /** @var array<string, string> Form for the 'provision' mode. */
    public array $provisionForm = [
        'name' => '',
        'provider' => 'digitalocean_spaces',
        'region' => '',
        'bucket' => '',
        'access_key' => '',
        'secret' => '',
    ];

    /** Reuse a saved ObjectStorageCredential instead of entering keys (manual-key providers). */
    public string $provision_credential_id = '';

    /** Save the entered keys as a reusable ObjectStorageCredential (manual-key providers). */
    public bool $provision_save_credential = true;

    public function openDestinationModal(): void
    {
        $this->authorize('create', BackupConfiguration::class);
        $this->resetErrorBag();
        $this->destinationForm = $this->emptyDestinationForm();
        $this->destination_create_mode = 'connect';
        $this->resetProvisionForm();
        $this->showDestinationModal = true;
    }

    public function closeDestinationModal(): void
    {
        $this->showDestinationModal = false;
        $this->destinationForm = $this->emptyDestinationForm();
        $this->destination_create_mode = 'connect';
        $this->resetProvisionForm();
        $this->resetErrorBag();
    }

    protected function resetProvisionForm(): void
    {
        $this->provisionForm = [
            'name' => '',
            'provider' => 'digitalocean_spaces',
            'region' => '',
            'bucket' => '',
            'access_key' => '',
            'secret' => '',
        ];
        $this->provision_credential_id = '';
        $this->provision_save_credential = true;
    }

    /**
     * Can dply mint object-storage keys for the selected provider from a
     * connected cloud API token? True for api_managed providers (DigitalOcean
     * Spaces) when the org has a matching ProviderCredential — in that case the
     * operator never pastes keys.
     */
    public function provisionCanAutoMint(): bool
    {
        return $this->autoMintProviderCredential($this->provisionForm['provider'] ?? '') !== null;
    }

    protected function autoMintProviderCredential(string $provider): ?ProviderCredential
    {
        $meta = (array) config('object_storage.providers.'.$provider, []);
        $apiProvider = (string) ($meta['api_provider'] ?? '');
        if (! (bool) ($meta['api_managed'] ?? false) || $apiProvider === '' || $this->server->organization_id === null) {
            return null;
        }

        return ProviderCredential::query()
            ->where('organization_id', $this->server->organization_id)
            ->where('provider', $apiProvider)
            ->orderBy('created_at')
            ->first();
    }

    /**
     * Pricing + cold-storage metadata for a provider slug (object_storage.php),
     * for the modal's "billed by the provider, no cut" panel.
     *
     * @return array{note: string, url: string, cold_note: string, cold_console_url: string}
     */
    public function objectStoragePricing(string $provider): array
    {
        $meta = (array) config('object_storage.providers.'.$provider, []);

        return [
            'note' => (string) ($meta['pricing_note'] ?? ''),
            'url' => (string) ($meta['pricing_url'] ?? ''),
            'cold_note' => (string) ($meta['cold_note'] ?? ''),
            'cold_console_url' => (string) ($meta['cold_console_url'] ?? ''),
        ];
    }

    public function objectStorageNoCutDisclaimer(): string
    {
        return (string) config('object_storage.no_cut_disclaimer', '');
    }

    /**
     * Saved object-storage credentials for the selected provider, offered as a
     * "reuse keys" picker for manual-key providers (e.g. Hetzner).
     *
     * @return Collection<int, ObjectStorageCredential>
     */
    public function savedObjectStorageCredentials(): Collection
    {
        if ($this->server->organization_id === null) {
            return collect();
        }

        return ObjectStorageCredential::query()
            ->where('organization_id', $this->server->organization_id)
            ->where('provider', $this->provisionForm['provider'] ?? '')
            ->orderBy('name')
            ->get();
    }

    /**
     * Providers we can create a bucket on inline. Sourced from
     * config/object_storage.php (provision-capable only) so the picker and the
     * provisioner agree on what's possible.
     *
     * @return array<string, array{label: string, regions: array<string, string>}>
     */
    public function provisionableObjectStorageProviders(): array
    {
        $out = [];
        foreach ((array) config('object_storage.providers', []) as $key => $meta) {
            if (! is_array($meta) || ! (bool) ($meta['provision'] ?? false)) {
                continue;
            }
            $out[$key] = [
                'label' => (string) ($meta['label'] ?? $key),
                'regions' => (array) ($meta['regions'] ?? []),
                'key_help' => (string) ($meta['key_help'] ?? ''),
                'key_console_url' => (string) ($meta['key_console_url'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * Create a brand-new bucket on the chosen provider and wire it up as a
     * backup destination — the "create an S3 from here" path. Uses the operator
     * S3 keys entered in the form; the bucket is created via a single
     * CreateBucket call, then persisted as a BackupConfiguration.
     */
    public function provisionDestinationBucket(ObjectStorageBucketProvisioner $provisioner): void
    {
        $this->authorize('create', BackupConfiguration::class);

        $org = $this->server->organization;
        if ($org === null) {
            $this->toastError(__('This server has no organization — refresh the page.'));

            return;
        }

        $this->resetErrorBag();
        $providers = $this->provisionableObjectStorageProviders();
        $provider = $this->provisionForm['provider'];

        $providerCredential = $this->autoMintProviderCredential($provider);
        $reuseId = trim($this->provision_credential_id);
        // Keys are needed manually only when we can't mint them AND the operator
        // isn't reusing a saved credential.
        $needsManualKeys = $providerCredential === null && $reuseId === '';

        $rules = [
            'provisionForm.name' => ['required', 'string', 'max:160'],
            'provisionForm.provider' => ['required', 'string', Rule::in(array_keys($providers))],
            'provisionForm.region' => ['required', 'string', 'max:100'],
            'provisionForm.bucket' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9][a-z0-9.\-]{1,61}[a-z0-9]$/'],
        ];
        if ($needsManualKeys) {
            $rules['provisionForm.access_key'] = ['required', 'string', 'max:500'];
            $rules['provisionForm.secret'] = ['required', 'string', 'max:4000'];
        }
        $this->validate($rules, [], ['provisionForm.bucket' => __('bucket name')]);

        $region = trim($this->provisionForm['region']);
        $bucket = trim($this->provisionForm['bucket']);

        // Resolve the S3 keys: mint from the cloud token, reuse a saved
        // credential, or use the keys typed into the form.
        $savedCredential = null;
        if ($providerCredential instanceof ProviderCredential) {
            try {
                // api_managed providers are DigitalOcean-only today (see object_storage.php).
                $minted = (new DigitalOceanService($providerCredential))->createSpacesKey('dply-'.$bucket, []);
                $accessKey = (string) $minted['access_key'];
                $secret = (string) $minted['secret_key'];
            } catch (\Throwable $e) {
                $this->addError('provisionForm.bucket', __('Could not create storage keys from your connected token: :err', ['err' => $e->getMessage()]));

                return;
            }
        } elseif ($reuseId !== '') {
            $savedCredential = ObjectStorageCredential::query()
                ->where('organization_id', $org->id)
                ->where('provider', $provider)
                ->whereKey($reuseId)
                ->first();
            if (! $savedCredential instanceof ObjectStorageCredential) {
                $this->addError('provision_credential_id', __('That saved storage credential is no longer available.'));

                return;
            }
            $accessKey = (string) $savedCredential->access_key_id;
            $secret = (string) $savedCredential->secret_access_key;
        } else {
            $accessKey = trim($this->provisionForm['access_key']);
            $secret = $this->provisionForm['secret'];
        }

        // When we just minted the key from the cloud token, give the S3 gateway
        // a moment to activate it (DO Spaces keys aren't usable instantly).
        $freshlyMinted = $providerCredential instanceof ProviderCredential;
        try {
            $result = $provisioner->create($provider, $region, $accessKey, $secret, $bucket, awaitKeyPropagation: $freshlyMinted);
        } catch (\Throwable $e) {
            $this->addError('provisionForm.bucket', $e->getMessage());

            return;
        }

        // Persist manually-entered keys for reuse when asked (minted/reused keys
        // are already managed or saved).
        if ($needsManualKeys && $this->provision_save_credential) {
            ObjectStorageCredential::query()->create([
                'organization_id' => $org->id,
                'created_by_user_id' => Auth::id(),
                'provider' => $provider,
                'name' => ($providers[$provider]['label'] ?? $provider).' '.__('keys'),
                'access_key_id' => $accessKey,
                'secret_access_key' => $secret,
                'region' => $region !== '' ? $region : null,
                'endpoint' => $result['endpoint'] !== '' ? $result['endpoint'] : null,
            ]);
        }

        // Map the object-storage provider onto a BackupConfiguration provider the
        // database exporter's S3 client factory understands. DO Spaces has its
        // own entry; everything else (e.g. Hetzner) rides Custom S3 with an
        // explicit endpoint + path-style addressing.
        $backupProvider = $provider === 'digitalocean_spaces'
            ? BackupConfiguration::PROVIDER_DIGITALOCEAN_SPACES
            : BackupConfiguration::PROVIDER_CUSTOM_S3;

        $row = $org->backupConfigurations()->create([
            'name' => $this->provisionForm['name'],
            'provider' => $backupProvider,
            'config' => [
                'access_key' => $accessKey,
                'secret' => $secret,
                'bucket' => $bucket,
                'region' => $region,
                'endpoint' => $result['endpoint'],
                'use_path_style' => $provider !== 'digitalocean_spaces',
            ],
            'created_by_user_id' => Auth::id(),
        ]);

        $this->onBackupDestinationCreated($row);

        $this->showDestinationModal = false;
        $this->destinationForm = $this->emptyDestinationForm();
        $this->destination_create_mode = 'connect';
        $this->resetProvisionForm();
        $this->toastSuccess(__('Created bucket :bucket and added it as a backup destination.', ['bucket' => $bucket]));
    }

    public function saveDestination(): void
    {
        $this->authorize('create', BackupConfiguration::class);

        $org = $this->server->organization;
        if ($org === null) {
            $this->toastError(__('This server has no organization — refresh the page.'));

            return;
        }

        $this->resetErrorBag();
        $this->validate($this->destinationFormRules('destinationForm', $this->destinationForm['provider'] ?? ''));
        $this->validateDestinationFormExtras('destinationForm', $this->destinationForm);

        $row = $org->backupConfigurations()->create([
            'name' => $this->destinationForm['name'],
            'provider' => $this->destinationForm['provider'],
            'config' => $this->extractDestinationConfig($this->destinationForm),
            'created_by_user_id' => Auth::id(),
        ]);

        $this->onBackupDestinationCreated($row);

        $this->showDestinationModal = false;
        $this->destinationForm = $this->emptyDestinationForm();
        $this->destination_create_mode = 'connect';
        $this->resetProvisionForm();
        $this->toastSuccess(__('Backup destination added.'));
    }

    /**
     * Hook for host components to react to a newly-created destination — e.g.
     * auto-select it on a schedule form. No-op by default.
     */
    protected function onBackupDestinationCreated(BackupConfiguration $destination): void
    {
        //
    }
}
