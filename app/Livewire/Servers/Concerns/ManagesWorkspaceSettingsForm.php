<?php

namespace App\Livewire\Servers\Concerns;

use App\Services\Servers\ServerSshAccessRepairer;
use Illuminate\Validation\Rule;

trait ManagesWorkspaceSettingsForm
{
    use RunsServerInventoryProbe;

    public string $settingsName = '';

    public string $settingsTags = '';

    public string $settingsIpAddress = '';

    public string $settingsInternalIp = '';

    public string $settingsSshPort = '22';

    public string $settingsSshUser = 'root';

    public string $settingsOsVersion = '';

    public ?string $settingsWorkspaceId = null;

    public string $settingsTimezone = 'UTC';

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
        $meta['internal_ip'] = trim($this->settingsInternalIp) ?: null;
        if ($meta['internal_ip'] === null) {
            unset($meta['internal_ip']);
        }
        $meta['os_version'] = $this->settingsOsVersion !== '' ? $this->settingsOsVersion : null;
        if (($meta['os_version'] ?? null) === null) {
            unset($meta['os_version']);
        }

        $this->server->update([
            'name' => trim($this->settingsName),
            'ip_address' => trim($this->settingsIpAddress) ?: null,
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
            $this->toastSuccess(__('SSH access repaired. Dply reinstalled the operational key for this server.'));
        } catch (\Throwable $e) {
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
        $meta['timezone'] = $this->settingsTimezone;
        $this->server->update(['meta' => $meta]);
        $this->server->refresh();
        $this->syncSettingsFormFromServer();
        $this->toastSuccess(__('Timezone preference saved (for your notes; Dply does not change the OS clock).'));
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
        $this->settingsInternalIp = (string) ($meta['internal_ip'] ?? '');
        $this->settingsSshPort = (string) ($s->ssh_port ?: 22);
        $this->settingsSshUser = (string) ($s->ssh_user ?: 'root');
        $this->settingsOsVersion = (string) ($meta['os_version'] ?? '');
        $this->settingsWorkspaceId = $s->workspace_id;
        $this->settingsTimezone = (string) ($meta['timezone'] ?? 'UTC');
        $this->settingsNotes = (string) ($meta['notes'] ?? '');
    }
}
