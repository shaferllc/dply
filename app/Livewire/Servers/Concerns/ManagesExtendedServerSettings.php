<?php

namespace App\Livewire\Servers\Concerns;

use Illuminate\Support\Facades\Crypt;
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

    public string $settingsCostMonthlyNote = '';

    public string $settingsCostRenewalDate = '';

    public string $settingsCostProviderUrl = '';

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
        $this->toastSuccess(__('Maintenance window saved. Dply will use this when scheduling supports it.'));
    }

    public function saveNotificationRouting(): void
    {
        $this->authorize('update', $this->server);
        if ($this->deployerCannotEditServerSettings()) {
            $this->toastError(__('Deployers cannot change server settings.'));

            return;
        }

        $this->validate([
            'settingsNotifRoutingNote' => ['nullable', 'string', 'max:5000'],
        ]);

        $meta = $this->server->meta ?? [];
        $meta['notif_routing_note'] = trim($this->settingsNotifRoutingNote) !== '' ? trim($this->settingsNotifRoutingNote) : null;
        if (($meta['notif_routing_note'] ?? null) === null) {
            unset($meta['notif_routing_note']);
        }
        $meta['notif_health_alerts'] = $this->settingsNotifHealthAlerts;

        $this->server->update(['meta' => $meta]);
        $this->server->refresh();
        $this->syncExtendedServerSettingsFromServer();
        $this->toastSuccess(__('Notification hints saved.'));
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
        $this->toastSuccess(__('Cost & lifecycle notes saved.'));
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
