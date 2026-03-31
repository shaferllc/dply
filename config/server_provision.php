<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Remote stack provisioning (SSH after VM is ready)
    |--------------------------------------------------------------------------
    |
    | Commands are built from servers.meta (wizard stack) by
    | App\Services\Servers\ServerProvisionCommandBuilder and run as one bash
    | script with set -euo pipefail. Target: Ubuntu 24.04 LTS (and generally
    | recent Debian/Ubuntu images from cloud providers).
    |
    */
    'remote_script_path' => '/tmp/dply-provision.sh',

    'remote_script_timeout_seconds' => 3600,

    /*
    |--------------------------------------------------------------------------
    | Wait for SSH after cloud assigns a public IP (before stack setup)
    |--------------------------------------------------------------------------
    */
    'ssh_ready_max_attempts' => max(5, (int) env('DPLY_SSH_READY_MAX_ATTEMPTS', 45)),

    'ssh_ready_retry_seconds' => max(3, (int) env('DPLY_SSH_READY_RETRY_SECONDS', 8)),

    /*
    |--------------------------------------------------------------------------
    | Deploy user created on the server (same key as root from provisioning)
    |--------------------------------------------------------------------------
    |
    | Stack setup runs as root, then the server row’s ssh_user is updated to this
    | account for subsequent TaskRunner / deploy connections. Root remains usable
    | with the same key on images that install it for cloud-init.
    |
    */
    'deploy_ssh_user' => env('DPLY_SERVER_DEPLOY_SSH_USER', 'dply'),

    /*
    |--------------------------------------------------------------------------
    | Optional extras for application / docker roles
    |--------------------------------------------------------------------------
    */
    'install_composer' => true,

    'install_fail2ban' => true,

    /*
    |--------------------------------------------------------------------------
    | Supervisor (process manager)
    |--------------------------------------------------------------------------
    |
    | When true (default), the stack provision script installs Supervisor during
    | initial setup. Set DPLY_INSTALL_SUPERVISOR_ON_PROVISION=false to skip (then use
    | Server → Daemons → “Install Supervisor” if you add programs later).
    |
    */
    'install_supervisor_on_provision' => (bool) env('DPLY_INSTALL_SUPERVISOR_ON_PROVISION', true),

];
