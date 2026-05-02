<?php

namespace App\Livewire\Servers\Concerns;

use App\Models\NotificationChannel;
use App\Models\NotificationSubscription;
use App\Models\Server;
use App\Services\Notifications\AssignableNotificationChannels;
use App\Services\Servers\ProviderCostUnavailableException;
use App\Services\Servers\ServerProviderCostEstimator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

trait ManagesExtendedServerSettings
{
    /** @var array<int, string> */
    public array $settingsMaintenanceDays = [];

    public string $settingsMaintenanceStart = '';

    public string $settingsMaintenanceEnd = '';

    public string $settingsMaintenanceNote = '';

    public string $settingsNotifRoutingNote = '';

    public bool $settingsNotifHealthAlerts = true;

    /** Channel selected in the "add subscription" form on the Alerts tab. */
    public string $notifAddChannelId = '';

    /**
     * Event keys ticked in the "add subscription" form. Restricted to server-scoped
     * notification keys (matches WorkspaceOverview's quick-add validation).
     *
     * @var list<string>
     */
    public array $notifAddEventKeys = [];

    public string $settingsCostMonthlyNote = '';

    public string $settingsCostRenewalDate = '';

    public string $settingsCostProviderUrl = '';

    /**
     * Most recent provider cost lookup, surfaced inline so the user can see
     * where the suggested figure came from. Cleared after Save.
     *
     * @var array{plan: string, provider_label: string, monthly: float, hourly: float, currency: string, fetched_at: string, mtd: float, ytd: float, runtime_hours_month: float, runtime_hours_year: float}|null
     */
    public ?array $lastPulledCostEstimate = null;

    public string $settingsEnvType = '';

    public string $settingsDataRegion = '';

    public string $settingsComplianceNote = '';

    public string $settingsBackupStrategy = '';

    public string $settingsBackupRpo = '';

    public string $settingsBackupRto = '';

    public string $settingsBackupRunbookUrl = '';

    public string $settingsWebhookUrl = '';

    /** Plaintext only when user changes it; empty means leave stored secret */
    public string $settingsWebhookSecret = '';

    public string $settingsInventoryDepth = 'basic';

    public function saveMaintenanceWindow(): void
    {
        $this->authorize('update', $this->server);
        if ($this->deployerCannotEditServerSettings()) {
            $this->toastError(__('Deployers cannot change server settings.'));

            return;
        }

        $allowed = array_keys(config('server_settings.maintenance_weekdays', []));
        $this->validate([
            'settingsMaintenanceDays' => ['array'],
            'settingsMaintenanceDays.*' => ['string', 'in:'.implode(',', $allowed)],
            'settingsMaintenanceStart' => ['nullable', 'date_format:H:i'],
            'settingsMaintenanceEnd' => ['nullable', 'date_format:H:i'],
            'settingsMaintenanceNote' => ['nullable', 'string', 'max:2000'],
        ]);

        $days = array_values(array_unique(array_intersect($this->settingsMaintenanceDays, $allowed)));

        $meta = $this->server->meta ?? [];
        $meta['maintenance_days'] = $days;
        $meta['maintenance_start'] = $this->settingsMaintenanceStart !== '' ? $this->settingsMaintenanceStart : null;
        $meta['maintenance_end'] = $this->settingsMaintenanceEnd !== '' ? $this->settingsMaintenanceEnd : null;
        $meta['maintenance_note'] = trim($this->settingsMaintenanceNote) !== '' ? trim($this->settingsMaintenanceNote) : null;
        foreach (['maintenance_start', 'maintenance_end', 'maintenance_note'] as $k) {
            if (($meta[$k] ?? null) === null) {
                unset($meta[$k]);
            }
        }
        if ($days === []) {
            unset($meta['maintenance_days']);
        }

        $this->server->update(['meta' => $meta]);
        $this->server->refresh();
        $this->syncExtendedServerSettingsFromServer();
        $this->toastSuccess(__('Maintenance window saved. Disruptive actions (firewall apply, supervisor restart-all) will warn outside this window.'));
    }

    public function addServerNotificationSubscription(): void
    {
        $this->authorize('update', $this->server);
        if ($this->deployerCannotEditServerSettings()) {
            $this->toastError(__('Deployers cannot change server settings.'));

            return;
        }

        $this->validate([
            'notifAddChannelId' => ['required', 'string', 'exists:notification_channels,id'],
            'notifAddEventKeys' => ['required', 'array', 'min:1'],
            'notifAddEventKeys.*' => ['string', 'in:server.automatic_updates,server.ssh_login,server.insights_alerts,server.monitoring'],
        ], [], [
            'notifAddChannelId' => __('channel'),
            'notifAddEventKeys' => __('notification types'),
        ]);

        $org = Auth::user()?->currentOrganization();
        $allowed = AssignableNotificationChannels::forUser(Auth::user(), $org)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        if (! in_array($this->notifAddChannelId, $allowed, true)) {
            $this->addError('notifAddChannelId', __('Channel is not assignable to this server.'));

            return;
        }

        $channel = NotificationChannel::query()->findOrFail($this->notifAddChannelId);
        Gate::authorize('manageNotificationChannels', $channel->owner);

        $created = 0;
        foreach ($this->notifAddEventKeys as $eventKey) {
            $row = NotificationSubscription::firstOrCreate([
                'notification_channel_id' => $channel->id,
                'subscribable_type' => Server::class,
                'subscribable_id' => $this->server->id,
                'event_key' => $eventKey,
            ]);
            if ($row->wasRecentlyCreated) {
                $created++;
            }
        }

        $this->notifAddChannelId = '';
        $this->notifAddEventKeys = [];
        $this->toastSuccess(__('Added :count subscription(s) routing this server\'s events to :channel.', [
            'count' => $created,
            'channel' => $channel->label,
        ]));
    }

    public function removeServerNotificationSubscription(string $subscriptionId): void
    {
        $this->authorize('update', $this->server);
        if ($this->deployerCannotEditServerSettings()) {
            $this->toastError(__('Deployers cannot change server settings.'));

            return;
        }

        $sub = NotificationSubscription::query()
            ->where('subscribable_type', Server::class)
            ->where('subscribable_id', $this->server->id)
            ->whereKey($subscriptionId)
            ->first();
        if ($sub === null) {
            return;
        }

        // Only allow removal when the user can manage the underlying channel — otherwise
        // an org member could detach a team-managed channel without permission.
        $channel = $sub->channel;
        if ($channel instanceof NotificationChannel) {
            Gate::authorize('manageNotificationChannels', $channel->owner);
        }

        $sub->delete();
        $this->toastSuccess(__('Subscription removed.'));
    }

    public function dismissLegacyRoutingNotes(): void
    {
        $this->authorize('update', $this->server);
        if ($this->deployerCannotEditServerSettings()) {
            $this->toastError(__('Deployers cannot change server settings.'));

            return;
        }

        $meta = $this->server->meta ?? [];
        unset($meta['notif_routing_note'], $meta['notif_health_alerts']);
        $this->server->update(['meta' => $meta]);
        $this->server->refresh();
        $this->syncExtendedServerSettingsFromServer();
        $this->toastSuccess(__('Legacy notes dismissed.'));
    }

    public function saveCostLifecycle(): void
    {
        $this->authorize('update', $this->server);
        if ($this->deployerCannotEditServerSettings()) {
            $this->toastError(__('Deployers cannot change server settings.'));

            return;
        }

        $this->validate([
            'settingsCostMonthlyNote' => ['nullable', 'string', 'max:2000'],
            'settingsCostRenewalDate' => ['nullable', 'date'],
            'settingsCostProviderUrl' => ['nullable', 'string', 'max:2048'],
        ]);

        $meta = $this->server->meta ?? [];
        $meta['cost_monthly_note'] = trim($this->settingsCostMonthlyNote) !== '' ? trim($this->settingsCostMonthlyNote) : null;
        $meta['cost_renewal_date'] = $this->settingsCostRenewalDate !== '' ? $this->settingsCostRenewalDate : null;
        $meta['cost_provider_console_url'] = trim($this->settingsCostProviderUrl) !== '' ? trim($this->settingsCostProviderUrl) : null;
        foreach (['cost_monthly_note', 'cost_renewal_date', 'cost_provider_console_url'] as $k) {
            if (($meta[$k] ?? null) === null) {
                unset($meta[$k]);
            }
        }

        $this->server->update(['meta' => $meta]);
        $this->server->refresh();
        $this->syncExtendedServerSettingsFromServer();
        $this->lastPulledCostEstimate = null;
        $this->toastSuccess(__('Cost & lifecycle notes saved.'));
    }

    /**
     * Whether the current server's provider is wired up for catalog-price lookup.
     */
    public function providerCostPullSupported(): bool
    {
        return ServerProviderCostEstimator::isSupported($this->server->provider ?? null)
            && $this->server->providerCredential !== null
            && (string) $this->server->size !== '';
    }

    /**
     * Fetches the catalog price from the provider API and pre-fills the
     * monthly-cost field. Does NOT save — the user reviews / edits / saves
     * manually so they can keep their own number if preferred.
     */
    public function pullCostFromProvider(ServerProviderCostEstimator $estimator): void
    {
        $this->authorize('view', $this->server);
        if ($this->deployerCannotEditServerSettings()) {
            $this->toastError(__('Deployers cannot change server settings.'));

            return;
        }

        try {
            $estimate = $estimator->estimate($this->server);
        } catch (ProviderCostUnavailableException $e) {
            $this->toastError($e->getMessage());

            return;
        } catch (\Throwable $e) {
            Log::warning('Provider cost pull failed', [
                'server_id' => $this->server->id,
                'provider' => $this->server->provider?->value,
                'error' => $e->getMessage(),
            ]);
            $this->toastError(__('Could not reach the provider API for pricing right now.'));

            return;
        }

        $this->settingsCostMonthlyNote = $estimate['formatted'];
        $this->lastPulledCostEstimate = [
            'plan' => $estimate['plan'],
            'provider_label' => $estimate['provider_label'],
            'monthly' => $estimate['monthly'],
            'hourly' => $estimate['hourly'],
            'currency' => $estimate['currency'],
            'fetched_at' => $estimate['fetched_at']->toIso8601String(),
            'mtd' => $estimate['mtd'],
            'ytd' => $estimate['ytd'],
            'runtime_hours_month' => $estimate['runtime_hours_month'],
            'runtime_hours_year' => $estimate['runtime_hours_year'],
        ];
        $this->toastSuccess(__('Pulled :provider catalog price. Review and click Save cost notes to keep it.', [
            'provider' => $estimate['provider_label'],
        ]));
    }

    public function saveComplianceSettings(): void
    {
        $this->authorize('update', $this->server);
        if ($this->deployerCannotEditServerSettings()) {
            $this->toastError(__('Deployers cannot change server settings.'));

            return;
        }

        $allowedEnv = array_keys(config('server_settings.environment_types', []));
        $this->validate([
            'settingsEnvType' => ['required', 'string', 'in:'.implode(',', $allowedEnv)],
            'settingsDataRegion' => ['nullable', 'string', 'max:255'],
            'settingsComplianceNote' => ['nullable', 'string', 'max:5000'],
        ]);

        $meta = $this->server->meta ?? [];
        $meta['env_type'] = $this->settingsEnvType;
        $meta['data_region_label'] = trim($this->settingsDataRegion) !== '' ? trim($this->settingsDataRegion) : null;
        $meta['compliance_note'] = trim($this->settingsComplianceNote) !== '' ? trim($this->settingsComplianceNote) : null;
        foreach (['data_region_label', 'compliance_note'] as $k) {
            if (($meta[$k] ?? null) === null) {
                unset($meta[$k]);
            }
        }

        $this->server->update(['meta' => $meta]);
        $this->server->refresh();
        $this->syncExtendedServerSettingsFromServer();
        $this->toastSuccess(__('Environment & compliance saved.'));
    }

    public function saveBackupDrHints(): void
    {
        $this->authorize('update', $this->server);
        if ($this->deployerCannotEditServerSettings()) {
            $this->toastError(__('Deployers cannot change server settings.'));

            return;
        }

        $this->validate([
            'settingsBackupStrategy' => ['nullable', 'string', 'max:5000'],
            'settingsBackupRpo' => ['nullable', 'string', 'max:500'],
            'settingsBackupRto' => ['nullable', 'string', 'max:500'],
            'settingsBackupRunbookUrl' => ['nullable', 'string', 'max:2048'],
        ]);

        $meta = $this->server->meta ?? [];
        foreach (
            [
                'backup_strategy_note' => $this->settingsBackupStrategy,
                'backup_rpo_note' => $this->settingsBackupRpo,
                'backup_rto_note' => $this->settingsBackupRto,
                'backup_runbook_url' => $this->settingsBackupRunbookUrl,
            ] as $key => $val
        ) {
            $t = trim($val);
            if ($t === '') {
                unset($meta[$key]);
            } else {
                $meta[$key] = $t;
            }
        }

        $this->server->update(['meta' => $meta]);
        $this->server->refresh();
        $this->syncExtendedServerSettingsFromServer();
        $this->toastSuccess(__('Backup & DR hints saved.'));
    }

    public function saveServerWebhooks(): void
    {
        $this->authorize('update', $this->server);
        if ($this->deployerCannotEditServerSettings()) {
            $this->toastError(__('Deployers cannot change server settings.'));

            return;
        }

        $this->validate([
            'settingsWebhookUrl' => ['nullable', 'string', 'max:2048'],
            'settingsWebhookSecret' => ['nullable', 'string', 'max:512'],
        ]);

        $meta = $this->server->meta ?? [];
        $url = trim($this->settingsWebhookUrl);
        if ($url === '') {
            unset($meta['server_event_webhook_url']);
            unset($meta['server_event_webhook_secret']);
        } else {
            $meta['server_event_webhook_url'] = $url;
            if ($this->settingsWebhookSecret !== '') {
                $meta['server_event_webhook_secret'] = Crypt::encryptString($this->settingsWebhookSecret);
            }
        }

        $this->server->update(['meta' => $meta]);
        $this->server->refresh();
        $this->settingsWebhookSecret = '';
        $this->syncExtendedServerSettingsFromServer();
        $this->toastSuccess(__('Webhook settings saved.'));
    }

    public function saveInventoryDepthPreference(): void
    {
        $this->authorize('update', $this->server);
        if ($this->deployerCannotEditServerSettings()) {
            $this->toastError(__('Deployers cannot change server settings.'));

            return;
        }

        $allowed = array_keys(config('server_settings.inventory_scan_depths', []));
        $this->validate([
            'settingsInventoryDepth' => ['required', 'string', 'in:'.implode(',', $allowed)],
        ]);

        $meta = $this->server->meta ?? [];
        $meta['inventory_scan_depth'] = $this->settingsInventoryDepth;
        $this->server->update(['meta' => $meta]);
        $this->server->refresh();
        $this->syncExtendedServerSettingsFromServer();
        $this->toastSuccess(__('Inventory scan depth saved.'));
    }

    public function downloadServerManifest(): StreamedResponse
    {
        $this->authorize('view', $this->server);
        $this->server->refresh();
        $this->server->load(['sites.domains', 'organization', 'workspace']);

        $payload = [
            'exported_at' => now()->toIso8601String(),
            'app' => config('app.name'),
            'server' => [
                'id' => $this->server->id,
                'name' => $this->server->name,
                'status' => $this->server->status,
                'health_status' => $this->server->health_status,
                'provider' => $this->server->provider?->value,
                'provider_id' => $this->server->provider_id,
                'region' => $this->server->region,
                'ip_address' => $this->server->ip_address,
                'ssh_port' => $this->server->ssh_port,
                'ssh_user' => $this->server->ssh_user,
                'workspace_id' => $this->server->workspace_id,
                'meta' => $this->manifestSafeMeta($this->server->meta ?? []),
            ],
            'sites' => $this->server->sites->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'domains' => $s->domains->pluck('domain')->all(),
            ])->values()->all(),
        ];

        $filename = 'server-'.$this->server->id.'-manifest.json';
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return response()->streamDownload(
            static function () use ($json): void {
                echo $json;
            },
            $filename,
            ['Content-Type' => 'application/json']
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    protected function manifestSafeMeta(array $meta): array
    {
        unset(
            $meta['manage_internal_db_password'],
            $meta['server_event_webhook_secret'],
        );

        return $meta;
    }

    protected function syncExtendedServerSettingsFromServer(): void
    {
        $m = $this->server->meta ?? [];

        $days = $m['maintenance_days'] ?? [];
        $this->settingsMaintenanceDays = is_array($days) ? $days : [];
        $this->settingsMaintenanceStart = (string) ($m['maintenance_start'] ?? '');
        $this->settingsMaintenanceEnd = (string) ($m['maintenance_end'] ?? '');
        $this->settingsMaintenanceNote = (string) ($m['maintenance_note'] ?? '');

        $this->settingsNotifRoutingNote = (string) ($m['notif_routing_note'] ?? '');
        $this->settingsNotifHealthAlerts = (bool) ($m['notif_health_alerts'] ?? true);

        $this->settingsCostMonthlyNote = (string) ($m['cost_monthly_note'] ?? '');
        $this->settingsCostRenewalDate = (string) ($m['cost_renewal_date'] ?? '');
        $this->settingsCostProviderUrl = (string) ($m['cost_provider_console_url'] ?? '');

        $this->settingsEnvType = (string) ($m['env_type'] ?? 'other');
        if ($this->settingsEnvType === '') {
            $this->settingsEnvType = 'other';
        }
        $this->settingsDataRegion = (string) ($m['data_region_label'] ?? '');
        $this->settingsComplianceNote = (string) ($m['compliance_note'] ?? '');

        $this->settingsBackupStrategy = (string) ($m['backup_strategy_note'] ?? '');
        $this->settingsBackupRpo = (string) ($m['backup_rpo_note'] ?? '');
        $this->settingsBackupRto = (string) ($m['backup_rto_note'] ?? '');
        $this->settingsBackupRunbookUrl = (string) ($m['backup_runbook_url'] ?? '');

        $this->settingsWebhookUrl = (string) ($m['server_event_webhook_url'] ?? '');
        $this->settingsWebhookSecret = '';

        $depth = (string) ($m['inventory_scan_depth'] ?? 'basic');
        $allowedDepth = array_keys(config('server_settings.inventory_scan_depths', ['basic' => '']));
        $this->settingsInventoryDepth = in_array($depth, $allowedDepth, true) ? $depth : 'basic';
    }
}
