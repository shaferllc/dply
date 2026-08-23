<?php

namespace App\Livewire\Credentials;

use App\Enums\ServerProvider;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\ManagesProviderCredentials;
use App\Livewire\Servers\Concerns\ManagesBackupDestinationModal;
use App\Models\BackupConfiguration;
use App\Models\BackupSchedule;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\RedisSnapshot;
use App\Support\ServerProviderGate;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The organization's Credentials page: every secret this org hands to a third
 * party, in one table.
 *
 * Two families live here and they ARE different shapes — {@see ProviderCredential}
 * is one API token per cloud/DNS/CDN provider, while {@see BackupConfiguration}
 * is a named bucket or remote with its own config, many per provider. They are
 * still listed together, because from the reader's side both answer the same
 * question: what have we handed out, and does it still work? {@see rows()} is
 * the projection that makes them comparable; nothing merges in the database.
 *
 * They remain separate in `credentialProviderNav()`: that list is also what the
 * server-create flow reads to decide where a VM can be provisioned, and an S3
 * bucket is not somewhere you can boot a server.
 *
 * The page used to render the whole provider catalog — 18 API providers plus 8
 * storage backends, 26 cards — while your own credentials were reachable only
 * by clicking a provider card to open a modal. The catalog now lives solely in
 * that modal's picker, which already listed every provider in a <select>.
 */
class Index extends Component
{
    use DispatchesToastNotifications;

    // Brings BOTH create modes — "connect existing" and "provision a new
    // bucket" — so a storage card here can create the bucket, not just record
    // keys for one you made elsewhere.
    use ManagesBackupDestinationModal;
    use ManagesProviderCredentials;

    /**
     * Row filters. Unlike the old capability tabs these narrow YOUR credentials,
     * not the catalog — "DNS" used to hide providers you had not connected
     * either way, which filtered nothing a reader could act on.
     *
     * @var list<string>
     */
    private const FILTERS = ['all', 'compute', 'dns', 'cdn', 'storage', 'attention'];

    public ?Organization $organization = null;

    #[Url(as: 'filter', except: 'all')]
    public string $filter = 'all';

    public function setFilter(string $value): void
    {
        $this->filter = in_array($value, self::FILTERS, true) ? $value : 'all';
    }

    /**
     * The add-credential modal saves into a different component, so without a
     * listener here its event never reaches this one and the table behind the
     * modal stays stale. Reset to "all" as well: a new row that lands outside
     * the active filter would otherwise look like the save did nothing.
     */
    #[On('provider-credential-created')]
    public function credentialCreated(): void
    {
        $this->filter = 'all';
    }

    public function mount(?Organization $organization = null): void
    {
        $this->destinationForm = $this->emptyDestinationForm();
        $this->organization = $organization;

        if ($this->organization) {
            $this->authorize('view', $this->organization);
            session(['current_organization_id' => $this->organization->id]);
        }

        $this->authorize('viewAny', ProviderCredential::class);

        // `?provider=` used to select a catalog card. It now opens that
        // provider's form straight away, which is what the link always meant.
        $q = request()->query('provider');
        if (is_string($q) && $q !== '' && ServerProviderGate::visible($q)) {
            $this->dispatch('open-add-provider-credential-modal', provider: $q);
        }
    }

    /**
     * Sidebar groups for the provider picker (IDs match `provider_credentials.provider` where applicable).
     *
     * @param  string|null  $capability  If set, restricts items to providers whose enum supports the capability
     *                                   ('compute' or 'dns'). Items with no matching enum case are dropped.
     * @return list<array{label: string, items: list<array{id: string, label: string, comingSoon: bool}>}>
     */
    public static function credentialProviderNav(?string $capability = null): array
    {
        $groups = [
            [
                'label' => __('VPS & cloud'),
                'items' => [
                    ['id' => 'digitalocean', 'label' => 'DigitalOcean'],
                    ['id' => 'hetzner', 'label' => 'Hetzner'],
                    ['id' => 'linode', 'label' => 'Linode'],
                    ['id' => 'vultr', 'label' => 'Vultr'],
                    ['id' => 'upcloud', 'label' => 'UpCloud'],
                ],
            ],
            [
                'label' => __('DNS & CDN'),
                'items' => [
                    ['id' => 'cloudflare', 'label' => 'Cloudflare'],
                    ['id' => 'gandi', 'label' => 'Gandi'],
                    ['id' => 'namecheap', 'label' => 'Namecheap'],
                    ['id' => 'vercel_dns', 'label' => __('Vercel DNS')],
                ],
            ],
            [
                'label' => __('Other providers'),
                'items' => [
                    ['id' => 'ovh', 'label' => __('OVH Public Cloud')],
                ],
            ],
            [
                'label' => __('Platforms'),
                'items' => [
                    ['id' => 'aws_app_runner', 'label' => 'AWS App Runner'],
                    ['id' => 'ghcr', 'label' => __('GitHub Container Registry')],
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
            [
                'label' => __('Migrate from'),
                'items' => [
                    ['id' => 'ploi', 'label' => 'Ploi'],
                    ['id' => 'forge', 'label' => 'Laravel Forge'],
                ],
            ],
        ];

        $filtered = [];
        foreach ($groups as $group) {
            $items = [];
            foreach ($group['items'] as $item) {
                if (! ServerProviderGate::visible($item['id'])) {
                    continue;
                }
                if ($capability !== null) {
                    $enum = ServerProvider::tryFrom($item['id']);
                    if ($enum === null) {
                        continue;
                    }
                    $matches = match ($capability) {
                        'dns' => $enum->supportsDns(),
                        'cdn' => $enum->supportsCdn(),
                        'import' => $enum->supportsImport(),
                        default => $enum->supportsCompute(),
                    };
                    if (! $matches) {
                        continue;
                    }
                }
                $items[] = [
                    'id' => $item['id'],
                    'label' => $item['label'],
                    'comingSoon' => ServerProviderGate::comingSoon($item['id']),
                ];
            }
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
     * @param  string|null  $capability  Optional capability filter forwarded to {@see credentialProviderNav()}.
     * @return list<string>
     */
    public static function credentialProviderIds(?string $capability = null): array
    {
        $ids = [];
        foreach (self::credentialProviderNav($capability) as $group) {
            foreach ($group['items'] as $item) {
                $ids[] = $item['id'];
            }
        }

        return $ids;
    }

    public static function providerLabel(string $providerId): string
    {
        foreach (self::credentialProviderNav() as $group) {
            foreach ($group['items'] as $item) {
                if ($item['id'] === $providerId) {
                    return $item['label'];
                }
            }
        }

        return $providerId;
    }

    /**
     * Open the shared add-destination modal. The provider is optional now that
     * the entry point is a header button rather than a per-provider card.
     */
    public function openStorageModal(string $provider = ''): void
    {
        $this->authorize('create', BackupConfiguration::class);
        $this->resetErrorBag();

        $this->destinationForm = $this->emptyDestinationForm();
        $this->destination_create_mode = 'connect';
        $this->resetProvisionForm();

        if ($provider !== '' && in_array($provider, BackupConfiguration::providers(), true)) {
            $this->destinationForm['provider'] = $provider;
        }

        $this->showDestinationModal = true;
    }

    /**
     * Destinations had no delete anywhere in the app — you could add a bucket
     * and never remove it. Confirm names what breaks, because the schedules
     * pointing at it are the reason not to do this by accident.
     */
    public function promptDeleteDestination(string $destinationId): void
    {
        $destination = $this->destinationForOrg($destinationId);
        $this->authorize('delete', $destination);

        $schedules = BackupSchedule::where('backup_configuration_id', $destination->id)->count();

        $this->openConfirmActionModal(
            'deleteDestination',
            [$destinationId],
            __('Remove backup destination'),
            $schedules > 0
                ? trans_choice(
                    'Remove :name? :count schedule still ships here and will keep its dumps on the server that made them instead.|Remove :name? :count schedules still ship here and will keep their dumps on the server that made them instead.',
                    $schedules,
                    ['name' => $destination->name, 'count' => $schedules],
                )
                : __('Remove :name? Nothing ships here yet. Backups already stored in it are not deleted — remove them at the provider.', ['name' => $destination->name]),
            __('Remove'),
            true,
        );
    }

    public function deleteDestination(string $destinationId): void
    {
        $destination = $this->destinationForOrg($destinationId);
        $this->authorize('delete', $destination);

        $snapshot = ['name' => $destination->name, 'provider' => $destination->provider];
        // destinationForOrg() already scoped the lookup to this org and aborted
        // if there wasn't one, so this is the same organization it belongs to.
        $org = $this->currentOrg();

        DB::transaction(function () use ($destination): void {
            // server_database_backups has an FK with nullOnDelete, so the DB
            // clears that one. These two columns are plain nullable char(26)
            // with no constraint behind them — left alone they would point at a
            // row that no longer exists and the next run would fail mid-ship.
            BackupSchedule::where('backup_configuration_id', $destination->id)
                ->update(['backup_configuration_id' => null]);
            RedisSnapshot::where('backup_configuration_id', $destination->id)
                ->update(['backup_configuration_id' => null]);

            $destination->delete();
        });

        if ($org !== null) {
            audit_log($org, Auth::user(), 'backup.destination.deleted', null, $snapshot, null);
        }

        $this->toastSuccess(__('Backup destination removed.'));
    }

    /** Scoped lookup: an id from another organization 404s rather than deleting. */
    private function destinationForOrg(string $destinationId): BackupConfiguration
    {
        $org = $this->currentOrg();
        abort_if($org === null, 404);

        return $org->backupConfigurations()->whereKey($destinationId)->firstOrFail();
    }

    /** This page scopes to an explicit organization, not the session's current one. */
    protected function backupDestinationOrganization(): ?Organization
    {
        return $this->organization ?: Auth::user()?->currentOrganization();
    }

    /** The trait creates the row; auditing is this surface's business. */
    protected function onBackupDestinationCreated(BackupConfiguration $destination): void
    {
        $org = $this->backupDestinationOrganization();
        if ($org === null) {
            return;
        }

        audit_log($org, Auth::user(), 'backup.destination.created', $destination, null, [
            'name' => $destination->name,
            'provider' => $destination->provider,
        ]);

        // No memo to invalidate: rows() queries fresh on every render. Reset the
        // filter for the same reason credentialCreated() does — a destination
        // saved while "DNS" is active would otherwise vanish on save.
        $this->filter = 'all';
    }

    /**
     * One row per secret, whichever family it comes from. The two models keep
     * their own tables and their own forms — this is a read projection so the
     * page can list them together and sort them by the only thing a reader
     * cares about first: whether it still works.
     *
     * @return list<array{kind: string, id: string, provider: string, providerLabel: string, name: string, usedFor: string, caps: list<string>, status: string, statusLabel: string, error: ?string, addedAt: ?Carbon}>
     */
    public function rows(): array
    {
        $org = $this->currentOrg();
        if ($org === null) {
            return $this->tokenRows(auth()->user()->providerCredentials()->whereNull('organization_id')->latest()->get());
        }

        $rows = array_merge(
            $this->tokenRows(ProviderCredential::where('organization_id', $org->id)->latest()->get()),
            $this->storageRows($org),
        );

        // Anything broken floats, then anything expiring, then newest first.
        $rank = ['bad' => 0, 'warn' => 1, 'ok' => 2, 'unknown' => 3];
        usort($rows, function (array $a, array $b) use ($rank): int {
            $byStatus = ($rank[$a['status']] ?? 9) <=> ($rank[$b['status']] ?? 9);

            return $byStatus !== 0
                ? $byStatus
                : ($b['addedAt']?->getTimestamp() ?? 0) <=> ($a['addedAt']?->getTimestamp() ?? 0);
        });

        return $rows;
    }

    /**
     * @param  Collection<int, ProviderCredential>  $credentials
     * @return list<array<string, mixed>>
     */
    private function tokenRows($credentials): array
    {
        return $credentials->map(function (ProviderCredential $credential): array {
            $caps = $credential->capabilities();

            if ($credential->isUnhealthy()) {
                $status = 'bad';
                $statusLabel = __('Can\'t connect');
            } elseif ($credential->last_validated_at !== null) {
                $status = 'ok';
                $statusLabel = __('Verified :time', ['time' => $credential->last_validated_at->diffForHumans()]);
            } else {
                // Never checked is not the same as healthy, and saying "Verified"
                // for a token nobody has called would be a lie.
                $status = 'unknown';
                $statusLabel = __('Not checked');
            }

            return [
                'kind' => 'token',
                'id' => (string) $credential->id,
                'provider' => $credential->provider,
                'providerLabel' => self::providerLabel($credential->provider),
                'name' => $credential->name !== '' ? (string) $credential->name : __('Untitled token'),
                'usedFor' => $caps === []
                    ? __('API access')
                    : collect($caps)->map(fn (string $c): string => self::capabilityLabel($c))->implode(' · '),
                'caps' => $caps,
                'status' => $status,
                'statusLabel' => $statusLabel,
                'error' => $credential->validation_error,
                'addedAt' => $credential->created_at,
            ];
        })->all();
    }

    /** @return list<array<string, mixed>> */
    private function storageRows(Organization $org): array
    {
        $destinations = $org->backupConfigurations()->orderBy('name')->get();
        if ($destinations->isEmpty()) {
            return [];
        }

        // One grouped count rather than a query per row — the schedule count is
        // the "used for" answer, so every row would otherwise ask for its own.
        $scheduleCounts = BackupSchedule::query()
            ->whereIn('backup_configuration_id', $destinations->pluck('id'))
            ->toBase()
            ->select('backup_configuration_id')
            ->selectRaw('count(*) as aggregate')
            ->groupBy('backup_configuration_id')
            ->pluck('aggregate', 'backup_configuration_id');

        return $destinations->map(function (BackupConfiguration $destination) use ($scheduleCounts): array {
            $schedules = (int) ($scheduleCounts[$destination->id] ?? 0);
            $expiring = ! $destination->hasDurableCredentials();

            return [
                'kind' => 'storage',
                'id' => (string) $destination->id,
                'provider' => $destination->provider,
                'providerLabel' => BackupConfiguration::labelForProvider($destination->provider),
                'name' => $destination->name !== '' ? (string) $destination->name : __('Untitled destination'),
                'usedFor' => $schedules > 0
                    ? __('Backups').' · '.trans_choice(':count schedule|:count schedules', $schedules, ['count' => $schedules])
                    : __('Backups').' · '.__('not used yet'),
                'caps' => ['storage'],
                'status' => $expiring ? 'warn' : 'ok',
                'statusLabel' => $expiring ? __('Token expires') : __('Ready'),
                'error' => $expiring
                    ? __('This uses a short-lived access token — add a refresh token to keep schedules working.')
                    : null,
                'addedAt' => $destination->created_at,
            ];
        })->all();
    }

    /** Rows left after the active filter. */
    public function visibleRows(): array
    {
        $rows = $this->rows();

        return match ($this->filter) {
            'storage' => array_values(array_filter($rows, fn (array $r): bool => $r['kind'] === 'storage')),
            'attention' => array_values(array_filter($rows, fn (array $r): bool => in_array($r['status'], ['bad', 'warn'], true))),
            'all' => $rows,
            default => array_values(array_filter(
                $rows,
                fn (array $r): bool => in_array($this->filter, $r['caps'], true),
            )),
        };
    }

    /**
     * Counts for the filter chips. A chip with nothing behind it is noise, so
     * the view hides any that come back zero.
     *
     * @return array<string, int>
     */
    public function filterCounts(): array
    {
        $rows = $this->rows();

        return [
            'all' => count($rows),
            'compute' => count(array_filter($rows, fn (array $r): bool => in_array('compute', $r['caps'], true))),
            'dns' => count(array_filter($rows, fn (array $r): bool => in_array('dns', $r['caps'], true))),
            'cdn' => count(array_filter($rows, fn (array $r): bool => in_array('cdn', $r['caps'], true))),
            'storage' => count(array_filter($rows, fn (array $r): bool => $r['kind'] === 'storage')),
            'attention' => count(array_filter($rows, fn (array $r): bool => in_array($r['status'], ['bad', 'warn'], true))),
        ];
    }

    public static function capabilityLabel(string $capability): string
    {
        return match ($capability) {
            'compute' => __('Compute'),
            'dns' => __('DNS'),
            'cdn' => __('CDN'),
            'app_platform' => __('App Platform'),
            'import' => __('Import'),
            'storage' => __('Storage'),
            default => ucfirst(str_replace('_', ' ', $capability)),
        };
    }

    private function currentOrg(): ?Organization
    {
        $org = $this->organization ?: Auth::user()?->currentOrganization();

        return $org instanceof Organization ? $org : null;
    }

    public function render(): View
    {
        $org = $this->currentOrg();

        return view('livewire.credentials.index', [
            'rows' => $this->visibleRows(),
            'counts' => $this->filterCounts(),
            'organization' => $org,
            'useOrgShell' => $org instanceof Organization,
        ])->layout($org instanceof Organization ? 'layouts.app' : 'layouts.settings');
    }
}
