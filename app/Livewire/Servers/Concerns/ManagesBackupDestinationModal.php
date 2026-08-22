<?php

namespace App\Livewire\Servers\Concerns;

use App\Livewire\Concerns\AuthorsBackupDestinations;
use App\Models\BackupConfiguration;
use App\Models\ObjectStorageCredential;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Modules\Providers\Services\DigitalOceanService;
use App\Services\Storage\ObjectStorageBucketProvisioner;
use Aws\S3\S3Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Reusable "Add backup destination" modal. Holds the modal state plus both
 * create modes — "connect existing" (paste credentials for an existing bucket)
 * and "provision" (create a brand-new bucket on a provider) — so every surface
 * that needs a {@see BackupConfiguration} opens the identical dialog rather
 * than bouncing the operator somewhere else.
 *
 * Pairs with `livewire.servers.partials.backups._add-destination-modal`.
 *
 * Hosts must extend {@see Component} and provide `toastSuccess`/`toastError`.
 * A server workspace gets its organization from `$this->server` automatically;
 * org-level surfaces (Backups → Storage, the org Credentials page) fall back to
 * the current organization. Override {@see backupDestinationOrganization()} to
 * change that, and {@see onBackupDestinationCreated()} to react to a freshly
 * created destination (e.g. auto-select it on a form).
 *
 * @phpstan-require-extends Component
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

    /** True once dply has minted Spaces keys from the connected DigitalOcean token. */
    public bool $doSpacesKeyMinted = false;

    /** @var list<array{bucket: string, region: string}> Spaces discovered on the account. */
    public array $doSpacesBuckets = [];

    /** Save the entered keys as a reusable ObjectStorageCredential (manual-key providers). */
    public bool $provision_save_credential = true;

    /** Set when the modal is editing an existing destination rather than creating one. */
    public ?string $destination_editing_id = null;

    /**
     * The organization the new destination belongs to. A server workspace host
     * carries its own server (which may not be the session's current org), so
     * that wins; org-level surfaces fall back to the current organization.
     */
    protected function backupDestinationOrganization(): ?Organization
    {
        $server = $this->server ?? null;

        if ($server instanceof Server) {
            return $server->organization;
        }

        return Auth::user()?->currentOrganization();
    }

    public function openDestinationModal(): void
    {
        $this->authorize('create', BackupConfiguration::class);
        $this->resetErrorBag();
        $this->destination_editing_id = null;
        $this->destinationForm = $this->emptyDestinationForm();
        $this->destination_create_mode = 'connect';
        $this->resetProvisionForm();
        $this->doSpacesKeyMinted = false;
        $this->doSpacesBuckets = [];
        $this->showDestinationModal = true;
    }

    public function closeDestinationModal(): void
    {
        $this->showDestinationModal = false;
        $this->destination_editing_id = null;
        $this->destinationForm = $this->emptyDestinationForm();
        $this->destination_create_mode = 'connect';
        $this->resetProvisionForm();
        $this->resetErrorBag();
    }

    /**
     * Open the same modal against an existing destination. Reuses the create
     * form wholesale — a second edit form would be the drift this trait exists
     * to prevent — and hydrates the stored config back into it.
     *
     * Credentials come back pre-filled so a rename doesn't force the operator to
     * re-enter a bucket secret they may no longer have to hand.
     */
    public function editDestination(string $destinationId): void
    {
        $org = $this->backupDestinationOrganization();
        if ($org === null) {
            $this->toastError(__('No active organization — refresh the page.'));

            return;
        }

        // Scope the lookup to the org: findOrFail alone would let a guessed id
        // from another organization load into the form.
        $destination = BackupConfiguration::query()
            ->where('organization_id', $org->id)
            ->whereKey($destinationId)
            ->first();

        if (! $destination instanceof BackupConfiguration) {
            $this->toastError(__('That destination is no longer available.'));

            return;
        }

        $this->authorize('update', $destination);

        $this->resetErrorBag();
        $this->destination_create_mode = 'connect';
        $this->resetProvisionForm();

        $this->destinationForm = $this->emptyDestinationForm();
        $this->destinationForm['name'] = $destination->name;
        $this->destinationForm['provider'] = $destination->provider;
        $this->hydrateDestinationFormFromConfig(
            $this->destinationForm,
            $destination->provider,
            $destination->config ?? [],
        );

        $this->destination_editing_id = (string) $destination->id;
        $this->showDestinationModal = true;
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
    /** Can dply mint Spaces keys for an EXISTING bucket from a connected DO token? */
    public function provisionCanAutoMintSpaces(): bool
    {
        return $this->autoMintProviderCredential(BackupConfiguration::PROVIDER_DIGITALOCEAN_SPACES) !== null;
    }

    public function provisionCanAutoMint(): bool
    {
        return $this->autoMintProviderCredential($this->provisionForm['provider'] ?? '') !== null;
    }

    protected function autoMintProviderCredential(string $provider): ?ProviderCredential
    {
        $meta = (array) config('object_storage.providers.'.$provider, []);
        $apiProvider = (string) ($meta['api_provider'] ?? '');
        $org = $this->backupDestinationOrganization();
        if (! (bool) ($meta['api_managed'] ?? false) || $apiProvider === '' || $org === null) {
            return null;
        }

        return ProviderCredential::query()
            ->where('organization_id', $org->id)
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
        $org = $this->backupDestinationOrganization();
        if ($org === null) {
            return collect();
        }

        return ObjectStorageCredential::query()
            ->where('organization_id', $org->id)
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
     * Attach an EXISTING DigitalOcean Space without pasting keys.
     *
     * The S3 protocol cannot use OAuth — signing needs a key pair — so "connect
     * with OAuth" is not literally possible. What is possible, and amounts to
     * the same thing for the operator, is minting a Spaces key from the
     * DigitalOcean token they already connected, then listing their Spaces with
     * it so they can just pick one.
     *
     * The minted key is account-wide, not bucket-scoped: discovery goes through
     * S3 ListBuckets, and a grant scoped to one bucket cannot enumerate the
     * account. Operators who want a narrower key can create one in the
     * DigitalOcean console and paste it into the fields below instead — the
     * panel is a shortcut, not the only way in.
     */
    public function loadDigitalOceanSpaces(): void
    {
        $this->authorize('create', BackupConfiguration::class);
        $this->resetErrorBag();

        $credential = $this->autoMintProviderCredential(BackupConfiguration::PROVIDER_DIGITALOCEAN_SPACES);
        if (! $credential instanceof ProviderCredential) {
            $this->addError('destinationForm.s3.bucket', __('Connect a DigitalOcean account first, then this can fill the keys in for you.'));

            return;
        }

        try {
            $minted = (new DigitalOceanService($credential))->createSpacesKey('dply-backups-'.now()->format('Ymd-His'), []);
        } catch (\Throwable $e) {
            $this->addError('destinationForm.s3.bucket', __('Could not create Spaces keys from your DigitalOcean token: :err', ['err' => $e->getMessage()]));

            return;
        }

        $this->destinationForm['s3']['access_key'] = (string) $minted['access_key'];
        $this->destinationForm['s3']['secret'] = (string) $minted['secret_key'];
        $this->doSpacesKeyMinted = true;

        // Listing needs a regional endpoint, but DO returns every Space whatever
        // region you ask — so any one works as the discovery endpoint.
        $this->doSpacesBuckets = $this->listDigitalOceanSpaces(
            (string) $minted['access_key'],
            (string) $minted['secret_key'],
        );

        $this->toastSuccess($this->doSpacesBuckets === []
            ? __('Keys created. Enter the Space name and region below.')
            : __('Keys created — pick the Space to back up to.'));
    }

    /**
     * Select a discovered Space and fill in bucket, region and endpoint.
     */
    public function useDigitalOceanSpace(string $bucket, string $region): void
    {
        $this->destinationForm['s3']['bucket'] = $bucket;
        $this->destinationForm['s3']['region'] = $region;
        $this->destinationForm['s3']['endpoint'] = str_replace(
            '{region}',
            $region,
            (string) config('object_storage.providers.digitalocean_spaces.endpoint_template'),
        );

        if (trim((string) ($this->destinationForm['name'] ?? '')) === '') {
            $this->destinationForm['name'] = 'DigitalOcean Spaces — '.$bucket;
        }
    }

    /**
     * Every Space on the account, via S3 ListBuckets with the freshly minted key.
     *
     * Best-effort: a failure here just means the operator types the name, which
     * is strictly better than blocking the flow on a discovery nicety.
     *
     * @return list<array{bucket: string, region: string}>
     */
    private function listDigitalOceanSpaces(string $accessKey, string $secret): array
    {
        $regions = array_keys((array) config('object_storage.providers.digitalocean_spaces.regions', []));
        $probeRegion = $regions[0] ?? 'nyc3';

        try {
            $client = new S3Client([
                'version' => 'latest',
                'region' => $probeRegion,
                'endpoint' => str_replace('{region}', $probeRegion, 'https://{region}.digitaloceanspaces.com'),
                'credentials' => ['key' => $accessKey, 'secret' => $secret],
            ]);

            $result = $client->listBuckets();
        } catch (\Throwable) {
            return [];
        }

        $spaces = [];
        foreach ((array) ($result['Buckets'] ?? []) as $bucket) {
            $name = (string) ($bucket['Name'] ?? '');
            // Space names are DNS labels; anything else is a parse artefact and
            // would be interpolated into a wire:click argument.
            if (preg_match('/^[a-z0-9][a-z0-9.-]{1,62}$/', $name) !== 1) {
                continue;
            }

            // ListBuckets does not report a region, so resolve each Space's own
            // location — a bucket written through the wrong regional endpoint
            // fails with a redirect the operator cannot debug.
            $region = $probeRegion;
            try {
                $region = $this->normaliseSpacesRegion(
                    $client->getBucketLocation(['Bucket' => $name])['LocationConstraint'] ?? null,
                    $probeRegion,
                );
            } catch (\Throwable) {
                // Keep the probe region; the operator can correct it.
            }

            $spaces[] = ['bucket' => $name, 'region' => $region];
        }

        return $spaces;
    }

    /**
     * Pull a bare region slug out of whatever GetBucketLocation hands back.
     *
     * DigitalOcean does not answer this call the way the AWS SDK's model
     * expects, so LocationConstraint arrives as the raw XML element
     * (`<LocationConstraint xmlns="…">nyc3</LocationConstraint>`) instead of the
     * parsed text. Rendered straight into the UI that reads as garbage, and fed
     * into an endpoint template it produces a URL that cannot resolve.
     *
     * Anything that is not a plausible region slug falls back to the probe
     * region — a wrong-but-editable value beats a broken endpoint.
     */
    private function normaliseSpacesRegion(mixed $raw, string $fallback): string
    {
        if (! is_string($raw) && ! is_numeric($raw)) {
            return $fallback;
        }

        $value = trim((string) $raw);

        // Strip the element wrapper when DO sends one, then any stray markup.
        if (preg_match('/<LocationConstraint[^>]*>(.*?)<\/LocationConstraint>/is', $value, $m) === 1) {
            $value = $m[1];
        }

        $value = trim(strip_tags($value));

        return preg_match('/^[a-z0-9][a-z0-9-]{1,31}$/', $value) === 1 ? $value : $fallback;
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

        $org = $this->backupDestinationOrganization();
        if ($org === null) {
            $this->toastError(__('No active organization — refresh the page.'));

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
        $org = $this->backupDestinationOrganization();
        if ($org === null) {
            $this->toastError(__('No active organization — refresh the page.'));

            return;
        }

        // Editing is authorized against the row itself; creating against the
        // model. Getting this backwards would let a viewer edit a destination.
        $editing = $this->editingDestination($org);
        if ($this->destination_editing_id !== null && $editing === null) {
            $this->toastError(__('That destination is no longer available.'));
            $this->closeDestinationModal();

            return;
        }

        $editing !== null
            ? $this->authorize('update', $editing)
            : $this->authorize('create', BackupConfiguration::class);

        $this->resetErrorBag();
        $this->validate($this->destinationFormRules('destinationForm', $this->destinationForm['provider'] ?? ''));
        $this->validateDestinationFormExtras('destinationForm', $this->destinationForm);

        $payload = [
            'name' => $this->destinationForm['name'],
            'provider' => $this->destinationForm['provider'],
            'config' => $this->extractDestinationConfig($this->destinationForm),
        ];

        if ($editing !== null) {
            $before = ['name' => $editing->name, 'provider' => $editing->provider];
            $editing->update($payload);

            audit_log($org, Auth::user(), 'backup.destination.updated', $editing, $before, [
                'name' => $editing->name,
                'provider' => $editing->provider,
            ]);

            $this->onBackupDestinationUpdated($editing);
            $this->closeDestinationModal();
            $this->toastSuccess(__('Backup destination updated.'));

            return;
        }

        $row = $org->backupConfigurations()->create($payload + [
            'created_by_user_id' => Auth::id(),
        ]);

        $this->onBackupDestinationCreated($row);

        $this->closeDestinationModal();
        $this->toastSuccess(__('Backup destination added.'));
    }

    /** The row this modal is editing, scoped to the org. Null when creating. */
    private function editingDestination(Organization $org): ?BackupConfiguration
    {
        if ($this->destination_editing_id === null) {
            return null;
        }

        return BackupConfiguration::query()
            ->where('organization_id', $org->id)
            ->whereKey($this->destination_editing_id)
            ->first();
    }

    /**
     * Hook for host components to react to an edited destination. No-op by
     * default.
     */
    protected function onBackupDestinationUpdated(BackupConfiguration $destination): void
    {
        //
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
