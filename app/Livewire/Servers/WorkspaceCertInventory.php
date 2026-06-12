<?php

declare(strict_types=1);

namespace App\Livewire\Servers;

use App\Livewire\Concerns\CreatesNotificationChannelInline;
use App\Livewire\Concerns\RequiresFeature;
use App\Livewire\Servers\Concerns\InteractsWithServerWorkspace;
use App\Livewire\Servers\Concerns\LoadsLiveServerCerts;
use App\Livewire\Servers\Concerns\ManagesCertInventoryNotifications;
use App\Livewire\Servers\Concerns\RendersWorkspacePlaceholder;
use App\Models\Server;
use App\Models\SiteCertificate;
use App\Services\Servers\ServerCertificateInventory;
use App\Services\Servers\WebserverCertsAggregator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('layouts.app')]
#[Lazy]
class WorkspaceCertInventory extends Component
{
    use CreatesNotificationChannelInline;
    use InteractsWithServerWorkspace;
    use LoadsLiveServerCerts;
    use ManagesCertInventoryNotifications;
    use RendersWorkspacePlaceholder;
    use RequiresFeature;

    protected string $requiredFeature = 'workspace.cert_inventory';

    /** In-page sub-tab: 'inventory' (the cert list) or 'notifications' (event routing). */
    public string $cert_workspace_tab = 'inventory';

    public bool $showRenewModal = false;

    /** @var 'all'|'attention'|'failed'|'expiring'|'pending'|'active' */
    public string $certFilter = 'all';

    public string $certSearch = '';

    public function mount(Server $server): void
    {
        $this->bootWorkspace($server);
        abort_unless($server->isVmHost(), 404);
    }

    public function setCertFilter(string $filter): void
    {
        $allowed = ['all', 'attention', 'failed', 'expiring', 'pending', 'active'];
        $this->certFilter = in_array($filter, $allowed, true) ? $filter : 'all';
    }

    public function setCertWorkspaceTab(string $tab): void
    {
        $this->cert_workspace_tab = in_array($tab, ['inventory', 'notifications'], true) ? $tab : 'inventory';
    }

    /**
     * Fired by {@see CreatesNotificationChannelInline} after the inline modal
     * creates a channel. Jump to the Notifications tab and pre-select the new
     * channel so the operator can finish wiring it to events in one motion.
     */
    #[On('notification-channel-created')]
    public function onNotificationChannelCreated(string $channelId): void
    {
        $this->cert_workspace_tab = 'notifications';
        $this->notif_channel_id = $channelId;
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

        // Managed certbot-issued certs never persist an expiry to the DB, so
        // back-fill `expires_at`/`days_left` from the cached live on-disk scan
        // when a domain matches. Read-only cache lookup — no SSH in the request.
        $liveScan = app(WebserverCertsAggregator::class)->cached($this->server);
        if ($liveScan !== null && ($liveScan['certs'] ?? []) !== []) {
            $report['items'] = $inventory->withLiveExpiry($report['items'], $liveScan['certs']);
        }

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
            'notifChannels' => $this->cert_workspace_tab === 'notifications' ? $this->assignableCertNotificationChannels() : collect(),
            'notifSubscriptions' => $this->cert_workspace_tab === 'notifications' ? $this->certNotificationSubscriptions() : collect(),
            'notifEventLabels' => $this->cert_workspace_tab === 'notifications' ? $this->certEventLabels() : [],
        ]);
    }
}
