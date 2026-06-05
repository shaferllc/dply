<?php

namespace App\Livewire\Servers\Concerns;

use App\Jobs\RefreshServerPrivateIpJob;
use App\Services\Servers\ServerSshAccessRepairer;
use App\Support\Servers\ServerDateFormatter;
use Illuminate\Validation\Rule;

trait ManagesWorkspaceSettingsForm
{
    use RunsServerInventoryProbe;

    public string $settingsName = '';

    public string $settingsTags = '';

    public string $settingsIpAddress = '';

    public string $settingsInternalIp = '';

    /** True while a queued provider internal-IP refresh is in flight; gates UI polling. */
    public bool $internalIpRefreshing = false;

    /** Epoch seconds the in-flight refresh was requested; bounds how long the UI polls. */
    public ?int $internalIpRefreshStartedAt = null;

    public string $settingsSshPort = '22';

    public string $settingsSshUser = 'root';

    public string $settingsOsVersion = '';

    public ?string $settingsWorkspaceId = null;

    public string $settingsTimezone = 'UTC';

    public string $settingsDateFormat = 'absolute_utc';

    public string $settingsNotes = '';

    public function saveServerSettingsInfo(): void
    {
        $this->authorize('update', $this->server);
        if ($this->deployerCannotEditServerSettings()) {
            $this->toastError(__('Deployers cannot change server settings.'));

            return;
        }

        $orgId = $this->server->organization_id;
        $this->validate([
            'settingsName' => ['required', 'string', 'max:120'],
            'settingsTags' => ['nullable', 'string', 'max:500'],
            'settingsIpAddress' => ['nullable', 'string', 'max:255'],
            'settingsInternalIp' => ['nullable', 'ip'],
            'settingsSshPort' => ['required', 'integer', 'min:1', 'max:65535'],
            'settingsSshUser' => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'settingsOsVersion' => ['nullable', 'string', 'max:64'],
            'settingsWorkspaceId' => [
                'nullable',
                'ulid',
                Rule::exists('workspaces', 'id')->where('organization_id', $orgId),
            ],
        ]);

        $tags = array_values(array_unique(array_filter(array_map('trim', explode(',', $this->settingsTags)))));

        $before = $this->server->fresh();
        $oldConnection = [
            'name' => $before->name,
            'ip_address' => $before->ip_address,
            'ssh_port' => $before->ssh_port,
            'ssh_user' => $before->ssh_user,
            'workspace_id' => $before->workspace_id,
        ];

        $meta = $this->server->meta ?? [];
        $meta['tags'] = $tags;
        // Legacy manual internal IP lived in meta; it now maps to the
        // private_ip_address column (same value provisioning + refresh write).
        unset($meta['internal_ip']);
        $meta['os_version'] = $this->settingsOsVersion !== '' ? $this->settingsOsVersion : null;
        if (($meta['os_version'] ?? null) === null) {
            unset($meta['os_version']);
        }

        $this->server->update([
            'name' => trim($this->settingsName),
            'ip_address' => trim($this->settingsIpAddress) ?: null,
            'private_ip_address' => trim($this->settingsInternalIp) ?: null,
            'ssh_port' => (int) $this->settingsSshPort,
            'ssh_user' => trim($this->settingsSshUser),
            'workspace_id' => $this->settingsWorkspaceId ?: null,
            'meta' => $meta,
        ]);

        $this->server->refresh();
        $newConnection = [
            'name' => $this->server->name,
            'ip_address' => $this->server->ip_address,
            'ssh_port' => $this->server->ssh_port,
            'ssh_user' => $this->server->ssh_user,
            'workspace_id' => $this->server->workspace_id,
        ];
        if ($oldConnection !== $newConnection) {
            $before->loadMissing('organization');
            $org = $before->organization;
            if ($org !== null) {
                audit_log($org, auth()->user(), 'server.settings_connection_updated', $this->server, $oldConnection, $newConnection);
            }
        }

        $this->syncSettingsFormFromServer();
        $this->toastSuccess(__('Server information saved.'));
    }

    /**
     * Whether the connection card should show the "Refresh" affordance next to
     * the Internal IP field: the provider must expose a private-IP lookup and the
     * server must still have a credential + provider server ID to query.
     */
    public function canRefreshInternalIp(): bool
    {
        $provider = $this->server->provider;

        return $provider !== null
            && $provider->supportsPrivateIpLookup()
            && $this->server->providerCredential !== null
            && filled($this->server->provider_id);
    }

    /**
     * Dispatch a queued job that re-queries the provider API for this server's
     * private/internal IP and updates the private_ip_address column. The provider
     * call must not run inline (PHP request timeout / project rule), so the UI
     * polls for the refreshed value after this returns.
     */
    public function refreshInternalIp(): void
    {
        $this->authorize('update', $this->server);
        if ($this->deployerCannotEditServerSettings()) {
            $this->toastError(__('Deployers cannot refresh server connection details.'));

            return;
        }

        if (! $this->canRefreshInternalIp()) {
            $this->toastError(__('This provider does not support internal IP lookup.'));

            return;
        }

        RefreshServerPrivateIpJob::dispatch((string) $this->server->id);

        $this->internalIpRefreshing = true;
        $this->internalIpRefreshStartedAt = now()->getTimestamp();

        $this->toastSuccess(__('Refreshing internal IP from the provider — the value updates here in a moment.'));
    }

    /**
     * Re-pull the server row and resync the connection form. Polled by the UI
     * while {@see $internalIpRefreshing} is set, so the new private_ip_address
     * appears without a manual page reload. Stops polling once the column value
     * changes from what was shown when the refresh was requested.
     */
    public function reloadInternalIp(): void
    {
        $previous = $this->settingsInternalIp;
        $this->server->refresh();

        // Only resync the internal IP — leave any other in-progress edits on the
        // connection form (name, tags, SSH) untouched while polling.
        $meta = $this->server->meta ?? [];
        $this->settingsInternalIp = (string) ($this->server->private_ip_address ?? $meta['internal_ip'] ?? '');

        $changed = $this->settingsInternalIp !== $previous;
        $timedOut = $this->internalIpRefreshStartedAt !== null
            && (now()->getTimestamp() - $this->internalIpRefreshStartedAt) > 45;

        if ($changed || $timedOut) {
            $this->internalIpRefreshing = false;
            $this->internalIpRefreshStartedAt = null;
        }
    }

    public function repairSshAccess(ServerSshAccessRepairer $repairer): void
    {
        $this->authorize('update', $this->server);
        if ($this->deployerCannotEditServerSettings()) {
            $this->toastError(__('Deployers cannot repair server SSH access.'));

            return;
        }

        try {
            $repairer->repairOperationalAccess($this->server->fresh());
            $this->server->refresh();
            if ($this->server->organization) {
                audit_log($this->server->organization, auth()->user(), 'server.ssh_access_repaired', $this->server, null, [
                    'result' => 'success',
                ]);
            }
            $this->toastSuccess(__('SSH access repaired. Dply reinstalled the operational key for this server.'));
        } catch (\Throwable $e) {
            if ($this->server->organization) {
                audit_log($this->server->organization, auth()->user(), 'server.ssh_access_repaired', $this->server, null, [
                    'result' => 'failed',
                    'error' => mb_strimwidth($e->getMessage(), 0, 500),
                ]);
            }
            $this->toastError($e->getMessage());
        }
    }

    public function saveServerTimezone(): void
    {
        $this->authorize('update', $this->server);
        if ($this->deployerCannotEditServerSettings()) {
            $this->toastError(__('Deployers cannot change server settings.'));

            return;
        }

        $this->validate([
            'settingsTimezone' => ['required', 'timezone:all'],
        ]);

        $meta = $this->server->meta ?? [];
        $old = $meta['timezone'] ?? null;
        $meta['timezone'] = $this->settingsTimezone;
        $this->server->update(['meta' => $meta]);
        $this->server->refresh();
        if ($old !== $this->settingsTimezone && $this->server->organization) {
            audit_log($this->server->organization, auth()->user(), 'server.timezone_updated', $this->server, ['timezone' => $old], ['timezone' => $this->settingsTimezone]);
        }
        $this->syncSettingsFormFromServer();
        $this->toastSuccess(__('Timezone preference saved (for your notes; Dply does not change the OS clock).'));
    }

    public function saveServerDateFormat(): void
    {
        $this->authorize('update', $this->server);
        if ($this->deployerCannotEditServerSettings()) {
            $this->toastError(__('Deployers cannot change server settings.'));

            return;
        }

        $allowed = array_keys((array) config('server_settings.date_formats', []));
        $this->validate([
            'settingsDateFormat' => ['required', 'string', 'in:'.implode(',', $allowed)],
        ]);

        $meta = $this->server->meta ?? [];
        $meta['date_format'] = $this->settingsDateFormat;
        $this->server->update(['meta' => $meta]);
        $this->server->refresh();
        $this->syncSettingsFormFromServer();
        $this->toastSuccess(__('Date format preference saved.'));
    }

    public function applyDetectedOsFromInventory(): void
    {
        $this->authorize('update', $this->server);
        if ($this->deployerCannotEditServerSettings()) {
            $this->toastError(__('Deployers cannot change server settings.'));

            return;
        }

        $meta = $this->server->meta ?? [];
        $key = $meta['inventory_os_detected_key'] ?? null;
        if (! is_string($key) || $key === '') {
            return;
        }

        $meta['os_version'] = $key;
        $this->server->update(['meta' => $meta]);
        $this->server->refresh();
        $this->syncSettingsFormFromServer();
        $this->toastSuccess(__('OS label updated to match the last inventory scan.'));
    }

    public function saveServerNotes(): void
    {
        $this->authorize('update', $this->server);
        if ($this->deployerCannotEditServerSettings()) {
            $this->toastError(__('Deployers cannot change server settings.'));

            return;
        }

        $this->validate(['settingsNotes' => ['nullable', 'string', 'max:10000']]);

        $meta = $this->server->meta ?? [];
        $meta['notes'] = trim($this->settingsNotes) ?: null;
        if ($meta['notes'] === null) {
            unset($meta['notes']);
        }
        $this->server->update(['meta' => $meta]);
        $this->server->refresh();
        $this->syncSettingsFormFromServer();
        $this->toastSuccess(__('Notes saved.'));
    }

    protected function deployerCannotEditServerSettings(): bool
    {
        return ! $this->canRunInventoryProbe();
    }

    protected function syncSettingsFormFromServer(): void
    {
        $s = $this->server;
        $meta = $s->meta ?? [];
        $this->settingsName = $s->name;
        $tags = $meta['tags'] ?? [];
        $this->settingsTags = is_array($tags) ? implode(', ', $tags) : (string) $tags;
        $this->settingsIpAddress = (string) ($s->ip_address ?? '');
        // Internal IP is the private_ip_address column (provisioning + provider
        // refresh write it); fall back to any legacy meta value for old rows.
        $this->settingsInternalIp = (string) ($s->private_ip_address ?? $meta['internal_ip'] ?? '');
        $this->settingsSshPort = (string) ($s->ssh_port ?: 22);
        $this->settingsSshUser = (string) ($s->ssh_user ?: 'root');
        $this->settingsOsVersion = (string) ($meta['os_version'] ?? '');
        $this->settingsWorkspaceId = $s->workspace_id;
        $this->settingsTimezone = (string) ($meta['timezone'] ?? 'UTC');
        $this->settingsDateFormat = ServerDateFormatter::resolveKey($s);
        $this->settingsNotes = (string) ($meta['notes'] ?? '');
    }
}
