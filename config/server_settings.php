<?php

use App\Support\Servers\ServerDateFormatter;

/**
 * Labels for the server "OS version" field (stored in server meta as `os_version` key).
 */
return [

    /**
     * Inventory probe runs as root SSH first when true (same key must authorize root), then falls
     * back to the deploy user if enabled and root login fails.
     */
    'inventory_use_root_ssh' => (bool) env('SERVER_SETTINGS_INVENTORY_USE_ROOT_SSH', true),

    'inventory_fallback_to_deploy_user_ssh' => (bool) env('SERVER_SETTINGS_INVENTORY_FALLBACK_SSH', true),

    /** Max lines of `apt list --upgradable` stored for the settings UI (after refresh inventory). */
    'inventory_package_preview_lines' => (int) env('SERVER_SETTINGS_INVENTORY_PKG_PREVIEW_LINES', 80),

    /** Max characters for the upgradable-packages preview in server meta (large lists are truncated). */
    'inventory_package_preview_max_bytes' => (int) env('SERVER_SETTINGS_INVENTORY_PKG_PREVIEW_MAX', 16384),

    /** Max characters for extended inventory snapshot (disk, memory, etc.). */
    'inventory_extended_max_bytes' => (int) env('SERVER_SETTINGS_INVENTORY_EXT_MAX_BYTES', 32000),

    /** SSH exec timeout (seconds) for inventory refresh when scan depth is basic. */
    'inventory_ssh_timeout_basic' => (int) env('SERVER_SETTINGS_INVENTORY_SSH_TIMEOUT_BASIC', 120),

    /** SSH exec timeout (seconds) when extended inventory commands are included. */
    'inventory_ssh_timeout_extended' => (int) env('SERVER_SETTINGS_INVENTORY_SSH_TIMEOUT_EXTENDED', 180),

    'os_versions' => [
        '' => 'Unknown / other',
        'ubuntu-22-04' => 'Ubuntu 22.04 LTS',
        'ubuntu-24-04' => 'Ubuntu 24.04 LTS',
        'debian-12' => 'Debian 12',
        'debian-13' => 'Debian 13',
        'rocky-9' => 'Rocky Linux 9',
        'almalinux-9' => 'AlmaLinux 9',
    ],

    /**
     * Curated IANA timezones for the server settings UI (preference only; does not change the OS).
     */
    'timezones' => [
        'UTC',
        'America/New_York',
        'America/Chicago',
        'America/Denver',
        'America/Los_Angeles',
        'America/Toronto',
        'America/Vancouver',
        'America/Sao_Paulo',
        'Europe/London',
        'Europe/Paris',
        'Europe/Berlin',
        'Europe/Amsterdam',
        'Asia/Tokyo',
        'Asia/Singapore',
        'Asia/Dubai',
        'Australia/Sydney',
    ],

    /** Weekday keys for maintenance window (stored in server meta). */
    'maintenance_weekdays' => [
        'mon' => 'Monday',
        'tue' => 'Tuesday',
        'wed' => 'Wednesday',
        'thu' => 'Thursday',
        'fri' => 'Friday',
        'sat' => 'Saturday',
        'sun' => 'Sunday',
    ],

    'environment_types' => [
        'production' => 'Production',
        'staging' => 'Staging',
        'development' => 'Development',
        'other' => 'Other',
    ],

    'inventory_scan_depths' => [
        'basic' => 'Basic (OS, apt, packages preview)',
        'extended' => 'Extended (+ disk, memory, uptime, fail2ban status)',
    ],

    /**
     * Per-server date display preference. The `key` is what gets saved
     * into server meta; the `label` is shown in the Settings → Reference
     * select. The {@see ServerDateFormatter} helper
     * resolves a key to a rendered string at view time.
     *
     * Adding a new option here automatically surfaces it in the picker.
     */
    'date_formats' => [
        'absolute_utc' => [
            'label' => 'Absolute UTC',
            'sample' => '2026-05-05 04:59:02 UTC',
        ],
        'absolute_local' => [
            'label' => 'Absolute (server timezone)',
            'sample' => '2026-05-05 00:59:02 EDT',
        ],
        'short_local' => [
            'label' => 'Short (server timezone)',
            'sample' => 'May 5 · 12:59 AM',
        ],
        'relative' => [
            'label' => 'Relative (e.g. "1 minute ago")',
            'sample' => '1 minute ago',
        ],
        'iso8601' => [
            'label' => 'ISO 8601',
            'sample' => '2026-05-05T04:59:02Z',
        ],
    ],

    /**
     * Server workspace Settings sub-pages (URL segment after /settings/).
     * Order matches the horizontal tab bar.
     */
    'workspace_tabs' => [
        'connection' => ['label' => 'Connection', 'icon' => 'link'],
        'keys' => ['label' => 'Keys', 'icon' => 'key'],
        'alerts' => ['label' => 'Alerts', 'icon' => 'bell-alert'],
        'inventory' => ['label' => 'Inventory', 'icon' => 'clipboard-document-list'],
        'governance' => ['label' => 'Cost', 'icon' => 'currency-dollar'],
        'notes' => ['label' => 'Notes', 'icon' => 'document-text'],
        'webhook' => ['label' => 'Webhook', 'icon' => 'bolt'],
        'export' => ['label' => 'Export', 'icon' => 'arrow-down-tray'],
        'danger' => ['label' => 'Danger', 'icon' => 'exclamation-triangle'],
    ],

];
