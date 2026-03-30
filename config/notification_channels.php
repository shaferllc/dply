<?php

/**
 * Which notification channel types appear in create/edit dropdowns.
 * Comma-separated type keys (see App\Models\NotificationChannel::* constants).
 * Add types here or via DPLY_NOTIFICATION_CHANNEL_TYPES when the product supports them end-to-end.
 */
$default = implode(',', [
    'slack',
    'discord',
    'email',
    'telegram',
    'webhook',
]);

$parsed = array_values(array_filter(array_map('trim', explode(',', env('DPLY_NOTIFICATION_CHANNEL_TYPES', $default)))));

return [
    'enabled_types' => $parsed,
];
