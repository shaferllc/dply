@php
    $osVersions = config('server_settings.os_versions', []);
    $tzPreset = config('server_settings.timezones', []);
    $inventoryDepths = config('server_settings.inventory_scan_depths', []);
    $meta = $server->meta ?? [];
    $upgrades = $meta['inventory_upgradable_packages'] ?? null;
    $reboot = $meta['inventory_reboot_required'] ?? null;
    $invAt = $meta['inventory_checked_at'] ?? null;
    $pkgPreview = isset($meta['inventory_upgradable_preview']) && is_string($meta['inventory_upgradable_preview'])
        ? $meta['inventory_upgradable_preview']
        : null;
    $extSnap = isset($meta['inventory_extended_snapshot']) && is_string($meta['inventory_extended_snapshot'])
        ? $meta['inventory_extended_snapshot']
        : null;
    $serverPub = $server->openSshPublicKeyFromPrivate();
    $credentialLabel = $server->providerCredential?->label ?? $server->providerCredential?->name;
    $providerLine = $server->provider?->label() ?? '—';
    if ($credentialLabel) {
        $providerLine = $providerLine.' ('.$credentialLabel.')';
    }
    $workspaceLabel = $workspaces->firstWhere('id', $server->workspace_id)?->name;

    $settingsShare = [
        'card' => $card,
        'server' => $server,
        'workspaces' => $workspaces,
        'meta' => $meta,
        'osVersions' => $osVersions,
        'tzPreset' => $tzPreset,
        'inventoryDepths' => $inventoryDepths,
        'upgrades' => $upgrades,
        'reboot' => $reboot,
        'invAt' => $invAt,
        'pkgPreview' => $pkgPreview,
        'extSnap' => $extSnap,
        'serverPub' => $serverPub,
        'providerLine' => $providerLine,
        'workspaceLabel' => $workspaceLabel,
    ];
@endphp

@switch ($section)
    @case ('connection')
        @include('livewire.servers.partials.settings.group-connect', $settingsShare)
        @break
    @case ('keys')
        @include('livewire.servers.partials.settings.group-keys', $settingsShare)
        @break
    @case ('alerts')
        @include('livewire.servers.partials.settings.group-operations', $settingsShare)
        @break
    @case ('inventory')
        @include('livewire.servers.partials.settings.group-inventory', $settingsShare)
        @break
    @case ('governance')
        @include('livewire.servers.partials.settings.group-governance', $settingsShare)
        @break
    @case ('notes')
        @include('livewire.servers.partials.settings.group-reference', $settingsShare)
        @break
    @case ('webhook')
        @include('livewire.servers.partials.settings.group-webhook', $settingsShare)
        @break
    @case ('export')
        @include('livewire.servers.partials.settings.group-export', $settingsShare)
        @break
    @case ('danger')
        @include('livewire.servers.partials.settings.group-danger', $settingsShare)
        @break
@endswitch

