<?php

declare(strict_types=1);

/**
 * Dogfood config: how dply registers + manages its OWN prod control-plane (see
 * deploy/SELF_MANAGE.md). Consumed by `dply:self:adopt`. All operator-provided;
 * the command no-ops the bits that aren't configured.
 */
return [
    // Ownership scope for the adopted Server/Site records.
    'organization_id' => env('SELF_MANAGE_ORGANIZATION_ID'),
    'user_id' => env('SELF_MANAGE_USER_ID'),
    'workspace_id' => env('SELF_MANAGE_WORKSPACE_ID'),

    // The prod control-plane box. SSH key VALUES are read from these file paths
    // at adopt time and stored encrypted on the Server record.
    'server' => [
        'name' => env('SELF_MANAGE_SERVER_NAME', 'dply-control-plane'),
        'ip_address' => env('SELF_MANAGE_SERVER_IP'),
        'ssh_port' => (int) env('SELF_MANAGE_SERVER_SSH_PORT', 22),
        'ssh_user' => env('SELF_MANAGE_SERVER_SSH_USER', 'dply'),
        'operational_key_path' => env('SELF_MANAGE_SERVER_OPERATIONAL_KEY_PATH'),
        'recovery_key_path' => env('SELF_MANAGE_SERVER_RECOVERY_KEY_PATH'),
    ],

    // Postgres superuser for the control-plane DB (for admin-level operations like
    // backup). The app's OWN DB connection (config/database) supplies the per-DB
    // name/user/password; this is the separate superuser.
    'postgres' => [
        'superuser' => env('SELF_MANAGE_PG_SUPERUSER', 'postgres'),
        'password' => env('SELF_MANAGE_PG_SUPERUSER_PASSWORD'),
        'use_sudo' => (bool) env('SELF_MANAGE_PG_USE_SUDO', false),
    ],

    // Which app DB connection IS the control-plane DB (defaults to the app default).
    'db_connection' => env('SELF_MANAGE_DB_CONNECTION'),

    /*
     * Phase-1 supervisor template sync (dply:self:sync-supervisor).
     * Prefer root dply.yaml `supervisor:` when present; these are fallbacks.
     * Set use_templates=false once dply-sv-* from processes: owns the box.
     */
    'supervisor' => [
        'use_templates' => filter_var(env('DPLY_SELF_SUPERVISOR_TEMPLATES', true), FILTER_VALIDATE_BOOLEAN),
        'conf_d' => env('DPLY_SELF_SUPERVISOR_CONF_D', '/etc/supervisor/conf.d'),
        'install_as' => env('DPLY_SELF_SUPERVISOR_INSTALL_AS', 'dply-platform.conf'),
        'supervisord_conf' => env('DPLY_SELF_SUPERVISORD_CONF', '/etc/supervisor/supervisord.conf'),
        'roles' => [
            'worker.primary' => 'deploy/supervisor/dply-worker-primary.conf',
            'worker.replica' => 'deploy/supervisor/dply-worker.conf',
            'web' => 'deploy/supervisor/dply-web.conf',
        ],
    ],
];
