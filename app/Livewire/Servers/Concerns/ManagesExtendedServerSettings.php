<?php

namespace App\Livewire\Servers\Concerns;

use App\Services\Servers\ProviderCostUnavailableException;
use App\Services\Servers\ServerProviderCostEstimator;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

trait ManagesExtendedServerSettings
{
    /** @var array<int, string> */
    public array $settingsMaintenanceDays = [];

    public string $settingsMaintenanceStart = '';

    public string $settingsMaintenanceEnd = '';

    public string $settingsMaintenanceNote = '';

    public string $settingsCostMonthlyNote = '';

    /**
     * Most recent provider cost lookup, surfaced inline so the user can see
     * where the suggested figure came from. Cleared after Save.
     *
     * @var array{plan: string, provider_label: string, monthly: float, hourly: float, currency: string, fetched_at: string, mtd: float, ytd: float, runtime_hours_month: float, runtime_hours_year: float}|null
     */
    public ?array $lastPulledCostEstimate = null;

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

    public function saveCostLifecycle(): void
    {
        $this->authorize('update', $this->server);
        if ($this->deployerCannotEditServerSettings()) {
            $this->toastError(__('Deployers cannot change server settings.'));

            return;
        }

        $this->validate([
            'settingsCostMonthlyNote' => ['nullable', 'string', 'max:2000'],
        ]);

        $meta = $this->server->meta ?? [];
        $meta['cost_monthly_note'] = trim($this->settingsCostMonthlyNote) !== '' ? trim($this->settingsCostMonthlyNote) : null;
        if (($meta['cost_monthly_note'] ?? null) === null) {
            unset($meta['cost_monthly_note']);
        }

        // Persist the most recent provider-pull estimate (catalog rate, MTD,
        // YTD, runtime hours) alongside the note so the inline summary cards
        // survive the save and are rebuilt on reload. Cleared when the user
        // wipes the monthly note (the figure no longer applies).
        if (($meta['cost_monthly_note'] ?? null) === null) {
            unset($meta['cost_pulled_estimate']);
        } elseif ($this->lastPulledCostEstimate !== null) {
            $meta['cost_pulled_estimate'] = $this->lastPulledCostEstimate;
        }

        $this->server->update(['meta' => $meta]);
        $this->server->refresh();
        $this->syncExtendedServerSettingsFromServer();
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

        $this->settingsCostMonthlyNote = (string) ($m['cost_monthly_note'] ?? '');

        $pulled = $m['cost_pulled_estimate'] ?? null;
        $this->lastPulledCostEstimate = is_array($pulled) ? $pulled : null;

        $this->settingsWebhookUrl = (string) ($m['server_event_webhook_url'] ?? '');
        $this->settingsWebhookSecret = '';

        $depth = (string) ($m['inventory_scan_depth'] ?? 'basic');
        $allowedDepth = array_keys(config('server_settings.inventory_scan_depths', ['basic' => '']));
        $this->settingsInventoryDepth = in_array($depth, $allowedDepth, true) ? $depth : 'basic';
    }
}
