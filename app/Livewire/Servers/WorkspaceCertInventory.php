<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Models\Server;
use App\Models\SiteCertificate;
use App\Services\Servers\ServerCertificateInventory;
use App\Services\Servers\WebserverCertsAggregator;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class WorkspaceCertInventory extends Component
{
    use InteractsWithServerWorkspace;
    use RequiresFeature;

    protected string $requiredFeature = 'workspace.cert_inventory';

    public bool $showRenewModal = false;

    /** @var 'all'|'attention'|'failed'|'expiring'|'pending'|'active' */
    public string $certFilter = 'all';

    public string $certSearch = '';

    /**
     * Live, on-disk server certificates surfaced from a single cross-engine SSH
     * sweep ({@see WebserverCertsAggregator}). This is how Caddy's automatic-HTTPS
     * certificates (managed by Caddy itself under /var/lib/caddy, never by certbot)
     * show up here — the managed-record table above is DB-only and is blind to them.
     * Loaded lazily via wire:init so the DB-backed page paints instantly.
     *
     * @var list<array<string, mixed>>
     */
    public array $liveCerts = [];

    public bool $liveCertsLoaded = false;

    public bool $liveCertsUnreadable = false;

    public ?string $liveCertsError = null;

    public ?string $liveCertsScannedAtIso = null;

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
        abort_unless($server->isVmHost(), 404);
    }

    /**
     * Cross-engine on-disk cert sweep — surfaces Caddy automatic-HTTPS certs and
     * any other live server certificate with its real expiry. Cached 60s on the
     * service; the Rescan button forces a fresh probe. Mirrors the webserver
     * Health tab's TLS dashboard so the two stay consistent.
     */
    public function loadLiveCerts(bool $forceFresh = false): void
    {
        $this->authorize('view', $this->server);

        if (! $this->serverOpsReady()) {
            $this->liveCertsError = __('Provisioning and SSH must be ready before scanning live certificates.');
            $this->liveCertsLoaded = true;

            return;
        }

        try {
            $result = app(WebserverCertsAggregator::class)->aggregate($this->server, $forceFresh);
            $this->liveCerts = array_map(function (array $row): array {
                $row['expires_at'] = $row['expires_at'] instanceof CarbonImmutable
                    ? $row['expires_at']->toIso8601String()
                    : null;

                return $row;
            }, $result['certs']);
            $this->liveCertsScannedAtIso = $result['scanned_at'] instanceof CarbonImmutable
                ? $result['scanned_at']->toIso8601String()
                : null;
            $this->liveCertsUnreadable = $result['unreadable'];
            $this->liveCertsLoaded = true;
            $this->liveCertsError = null;
        } catch (\Throwable $e) {
            $this->liveCertsError = __('Failed to scan live certificates: :msg', ['msg' => $e->getMessage()]);
            $this->liveCertsLoaded = true;
        }
    }

    public function refreshLiveCerts(): void
    {
        $this->loadLiveCerts(forceFresh: true);
    }

    public function setCertFilter(string $filter): void
    {
        $allowed = ['all', 'attention', 'failed', 'expiring', 'pending', 'active'];
        $this->certFilter = in_array($filter, $allowed, true) ? $filter : 'all';
    }

    public function openRenewModal(): void
    {
        $this->authorize('update', $this->server);
        $this->showRenewModal = true;
        $this->dispatch('open-modal', 'cert-inventory-renew');
    }

    public function closeRenewModal(): void
    {
        $this->showRenewModal = false;
        $this->dispatch('close-modal', 'cert-inventory-renew');
    }

    public function queueBulkRenew(ServerCertificateInventory $inventory): void
    {
        $this->authorize('update', $this->server);

        $result = $inventory->queueRenewals($this->server);
        $this->closeRenewModal();

        if ($result['queued'] === 0) {
            $this->toastError(__('No renewable certificates matched the expiring/failed criteria.'));

            return;
        }

        $this->toastSuccess(trans_choice(
            'Queued :count certificate renewal|Queued :count certificate renewals',
            $result['queued'],
            ['count' => $result['queued']],
        ));
    }

    public function queueSingleRenew(string $certificateId, ServerCertificateInventory $inventory): void
    {
        $this->authorize('update', $this->server);

        if (! $inventory->queueRenewal($certificateId, $this->server)) {
            $this->toastError(__('This certificate cannot be queued for renewal right now.'));

            return;
        }

        $this->toastSuccess(__('Certificate renewal queued.'));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function filteredCertItems(array $items): array
    {
        $collection = collect($items);

        $search = strtolower(trim($this->certSearch));
        if ($search !== '') {
            $collection = $collection->filter(function (array $item) use ($search): bool {
                if (str_contains(strtolower((string) ($item['site_name'] ?? '')), $search)) {
                    return true;
                }
                if (str_contains(strtolower((string) ($item['domain'] ?? '')), $search)) {
                    return true;
                }
                foreach ($item['all_domains'] ?? [] as $domain) {
                    if (is_string($domain) && str_contains(strtolower($domain), $search)) {
                        return true;
                    }
                }

                return false;
            });
        }

        $warningDays = (int) config('server_cert_inventory.warning_days', 30);

        $collection = match ($this->certFilter) {
            'attention' => $collection->whereIn('severity', ['critical', 'warning']),
            'failed' => $collection->where('status', SiteCertificate::STATUS_FAILED),
            'expiring' => $collection->filter(function (array $item) use ($warningDays): bool {
                $daysLeft = $item['days_left'] ?? null;

                return ($item['status'] ?? '') === SiteCertificate::STATUS_ACTIVE
                    && $daysLeft !== null
                    && (int) $daysLeft <= $warningDays;
            }),
            'pending' => $collection->whereIn('status', [
                SiteCertificate::STATUS_PENDING,
                SiteCertificate::STATUS_ISSUED,
                SiteCertificate::STATUS_INSTALLING,
            ]),
            'active' => $collection->where('status', SiteCertificate::STATUS_ACTIVE),
            default => $collection,
        };

        return $collection->values()->all();
    }

    public function render(ServerCertificateInventory $inventory): View
    {
        $this->server->refresh();
        $report = $inventory->forServer($this->server);

        return view('livewire.servers.workspace-cert-inventory', [
            'report' => $report,
            'filteredItems' => $this->filteredCertItems($report['items']),
            'bulkRenewEligible' => collect($report['items'])->contains(function (array $item) use ($report): bool {
                if (! ($item['renewable'] ?? false)) {
                    return false;
                }

                return ($item['status'] ?? '') === SiteCertificate::STATUS_FAILED
                    || ($item['status'] ?? '') === SiteCertificate::STATUS_EXPIRED
                    || (($item['days_left'] ?? null) !== null && (int) $item['days_left'] <= $report['warning_days']);
            }),
        ]);
    }
}
