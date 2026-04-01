<?php

namespace App\Livewire\Servers\Concerns;

use App\Livewire\Concerns\StreamsRemoteSshLivewire;
use App\Services\Servers\ServerInventoryOsDetector;
use App\Services\Servers\ServerSshAccessRepairer;
use App\Services\SshConnection;
use Illuminate\Validation\Rule;

trait ManagesWorkspaceSettingsForm
{
    use StreamsRemoteSshLivewire;

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
            $this->flash_error = __('Deployers cannot change server settings.');

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
        $this->flash_success = __('Server information saved.');
        $this->flash_error = null;
    }

    public function repairSshAccess(ServerSshAccessRepairer $repairer): void
    {
        $this->authorize('update', $this->server);
        if ($this->deployerCannotEditServerSettings()) {
            $this->flash_error = __('Deployers cannot repair server SSH access.');

            return;
        }

        try {
            $repairer->repairOperationalAccess($this->server->fresh());
            $this->server->refresh();
            $this->flash_success = __('SSH access repaired. Dply reinstalled the operational key for this server.');
            $this->flash_error = null;
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
        }
    }

    public function saveServerTimezone(): void
    {
        $this->authorize('update', $this->server);
        if ($this->deployerCannotEditServerSettings()) {
            $this->flash_error = __('Deployers cannot change server settings.');

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
        $this->flash_success = __('Timezone preference saved (for your notes; Dply does not change the OS clock).');
        $this->flash_error = null;
    }

    public function applyDetectedOsFromInventory(): void
    {
        $this->authorize('update', $this->server);
        if ($this->deployerCannotEditServerSettings()) {
            $this->flash_error = __('Deployers cannot change server settings.');

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
        $this->flash_success = __('OS label updated to match the last inventory scan.');
        $this->flash_error = null;
    }

    public function saveServerNotes(): void
    {
        $this->authorize('update', $this->server);
        if ($this->deployerCannotEditServerSettings()) {
            $this->flash_error = __('Deployers cannot change server settings.');

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
        $this->flash_success = __('Notes saved.');
        $this->flash_error = null;
    }

    public function refreshServerInventoryDetails(): void
    {
        $this->authorize('update', $this->server);
        if ($this->deployerCannotEditServerSettings()) {
            $this->flash_error = __('Deployers cannot run server inventory over SSH.');

            return;
        }

        if (! $this->server->isReady() || ! $this->server->ssh_private_key || empty($this->server->ip_address)) {
            $this->flash_error = __('Server must be ready with SSH before refreshing inventory.');

            return;
        }

        $script = $this->buildInventoryShellScript();
        $timeout = $this->inventorySshTimeoutSeconds();

        $wrapped = '/bin/sh -c '.escapeshellarg($script);
        $deploy = trim((string) $this->server->ssh_user) ?: 'root';
        $wantRoot = (bool) config('server_settings.inventory_use_root_ssh', true);
        $fallback = (bool) config('server_settings.inventory_fallback_to_deploy_user_ssh', true);
        $candidates = [];
        if ($wantRoot && $deploy !== 'root') {
            $candidates[] = 'root';
            if ($fallback) {
                $candidates[] = $deploy;
            }
        } else {
            $candidates[] = $deploy;
        }

        $this->resetRemoteSshStreamTargets();
        $lastError = null;
        $out = null;

        foreach ($candidates as $i => $loginUser) {
            $this->remoteSshStreamSetMeta(
                __('Refresh inventory'),
                sprintf('%s@%s  %s', $loginUser, $this->server->ip_address, $wrapped)
            );
            if ($i > 0) {
                $this->remoteSshStreamAppendStdout("\n\n--- ".__('Retrying as deploy SSH user')." ---\n\n");
            }

            try {
                $ssh = new SshConnection($this->server, $loginUser);
                $out = trim($ssh->execWithCallback(
                    $wrapped,
                    fn (string $chunk) => $this->remoteSshStreamAppendStdout($chunk),
                    $timeout,
                ));
                $ssh->disconnect();
                break;
            } catch (\Throwable $e) {
                $lastError = $e;
            }
        }

        if ($out === null) {
            $this->flash_error = $lastError !== null ? $lastError->getMessage() : __('SSH connection failed for inventory check.');

            return;
        }

        try {
            $osReleaseRaw = null;
            if (preg_match('/OS_BEGIN\s*\R(.*?)\ROS_END/s', $out, $osM)) {
                $osReleaseRaw = trim($osM[1]);
            }
            $detected = ServerInventoryOsDetector::fromOsRelease($osReleaseRaw ?? '');

            $reboot = null;
            $upgrades = null;
            foreach (explode("\n", $out) as $line) {
                if (str_starts_with($line, 'reboot=')) {
                    $reboot = trim(substr($line, 7)) === '1';
                }
                if (str_starts_with($line, 'upgrades=')) {
                    $upgrades = max(0, (int) trim(substr($line, 9)));
                }
            }

            $pkgPreview = null;
            if (preg_match('/PACKAGES_BEGIN\s*\R(.*?)\RPACKAGES_END/s', $out, $m)) {
                $pkgPreview = trim($m[1]);
            }
            $maxPreviewBytes = max(1024, (int) config('server_settings.inventory_package_preview_max_bytes', 16384));
            if ($pkgPreview !== null && strlen($pkgPreview) > $maxPreviewBytes) {
                $pkgPreview = substr($pkgPreview, 0, $maxPreviewBytes)."\n\n[dply] ".__('Preview truncated.');
            }

            $extendedSnapshot = null;
            if (preg_match('/EXTENDED_BEGIN\s*\R(.*?)\REXTENDED_END/s', $out, $ex)) {
                $extendedSnapshot = trim($ex[1]);
            }
            $maxExtBytes = (int) config('server_settings.inventory_extended_max_bytes', 32000);
            if ($extendedSnapshot !== null && strlen($extendedSnapshot) > $maxExtBytes) {
                $extendedSnapshot = substr($extendedSnapshot, 0, $maxExtBytes)."\n\n[dply] ".__('Preview truncated.');
            }

            $meta = $this->server->meta ?? [];
            $meta['inventory_reboot_required'] = $reboot;
            $meta['inventory_upgradable_packages'] = $upgrades;
            if ($detected['pretty'] !== null && $detected['pretty'] !== '') {
                $meta['inventory_os_pretty'] = $detected['pretty'];
            } else {
                unset($meta['inventory_os_pretty']);
            }
            if ($detected['key'] !== null) {
                $meta['inventory_os_detected_key'] = $detected['key'];
            } else {
                unset($meta['inventory_os_detected_key']);
            }
            $currentOs = (string) ($meta['os_version'] ?? '');
            if ($currentOs === '' && $detected['key'] !== null) {
                $meta['os_version'] = $detected['key'];
            }
            if ($pkgPreview !== null && $pkgPreview !== '') {
                $meta['inventory_upgradable_preview'] = $pkgPreview;
            } else {
                unset($meta['inventory_upgradable_preview']);
            }
            if ($extendedSnapshot !== null && $extendedSnapshot !== '') {
                $meta['inventory_extended_snapshot'] = $extendedSnapshot;
            } else {
                unset($meta['inventory_extended_snapshot']);
            }
            $meta['inventory_checked_at'] = now()->toIso8601String();
            $this->server->update(['meta' => $meta]);
            $this->server->refresh();
            $this->syncSettingsFormFromServer();
            if (method_exists($this, 'syncExtendedServerSettingsFromServer')) {
                $this->syncExtendedServerSettingsFromServer();
            }
            $this->flash_success = __('Server inventory refreshed from SSH.');
            $this->flash_error = null;
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
        }
    }

    protected function buildInventoryShellScript(): string
    {
        $previewLines = max(10, min(200, (int) config('server_settings.inventory_package_preview_lines', 80)));
        $depth = (string) (($this->server->fresh()->meta ?? [])['inventory_scan_depth'] ?? 'basic');
        $extended = $depth === 'extended';

        $script = <<<SH
printf "OS_BEGIN\n"
cat /etc/os-release 2>/dev/null || true
printf "OS_END\n"
rb=0
[ -f /var/run/reboot-required ] && rb=1
up=0
if command -v apt >/dev/null 2>&1; then
  up=\$(apt list --upgradable 2>/dev/null | tail -n +2 | wc -l | tr -d " ")
fi
printf "reboot=%s\nupgrades=%s\nPACKAGES_BEGIN\n" "\$rb" "\$up"
if command -v apt >/dev/null 2>&1; then
  apt list --upgradable 2>/dev/null | tail -n +2 | head -n {$previewLines}
fi
printf "PACKAGES_END\n"
SH;

        if ($extended) {
            $script .= <<<'SH'


printf "EXTENDED_BEGIN\n"
df -h 2>/dev/null | head -n 25
printf "\n---\n"
uptime 2>/dev/null || true
printf "\n---\n"
free -h 2>/dev/null | head -n 8 || true
printf "\n---\n"
(command -v systemctl >/dev/null 2>&1 && systemctl is-active fail2ban 2>/dev/null) || echo "n/a"
printf "EXTENDED_END\n"
SH;
        }

        return $script;
    }

    protected function inventorySshTimeoutSeconds(): int
    {
        $depth = (string) (($this->server->fresh()->meta ?? [])['inventory_scan_depth'] ?? 'basic');

        return $depth === 'extended'
            ? (int) config('server_settings.inventory_ssh_timeout_extended', 180)
            : (int) config('server_settings.inventory_ssh_timeout_basic', 120);
    }

    protected function deployerCannotEditServerSettings(): bool
    {
        return (bool) auth()->user()?->currentOrganization()?->userIsDeployer(auth()->user());
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
