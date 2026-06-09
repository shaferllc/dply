<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Server maintenance window
|--------------------------------------------------------------------------
|
| Server-scoped visitor maintenance: suspend all eligible VM sites on one
| host with a shared public message until the window is cleared.
|
*/

return [

    'suspended_reason' => 'server_maintenance',

    'meta_key' => 'maintenance',

    /*
    |--------------------------------------------------------------------------
    | Host upkeep operations
    |--------------------------------------------------------------------------
    |
    | Allowlist of server-level maintenance actions surfaced on the Maintenance
    | workspace, grouped for the UI. Each value is a key into
    | config('server_manage.service_actions') / .dangerous_actions — that file
    | remains the single source of truth for the label, confirm text, timeout,
    | and bash script. We only reference keys here so the page can run a curated
    | subset (and reject anything not listed) without duplicating scripts.
    |
    */
    'operations' => [
        'Packages' => ['apt_update', 'apt_upgrade', 'apt_dist_upgrade', 'apt_autoremove', 'apt_clean'],
        'Automatic updates' => ['unattended_upgrades_enable', 'unattended_upgrades_disable'],
        'Cleanup' => ['docker_system_prune', 'docker_image_prune', 'docker_volume_prune', 'mise_prune'],
        'Power' => ['reboot'],
    ],

];
