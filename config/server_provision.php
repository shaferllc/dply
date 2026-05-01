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
    | Log SSH readiness polling at info level every N attempts (1 = every attempt).
    | Attempts between those use debug level.
    */
    'ssh_ready_log_every_n_attempts' => max(1, (int) env('DPLY_SSH_READY_LOG_EVERY_N_ATTEMPTS', 5)),

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
    | Local retest override
    |--------------------------------------------------------------------------
    |
    | Set this to true in local development when you want provisioning to
    | reinstall packages even if the server already has them. Production
    | should normally leave this off so reruns stay idempotent.
    |
    */
    'force_reinstall' => (bool) env('DPLY_SERVER_PROVISION_FORCE_REINSTALL', false),

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

    /*
    |--------------------------------------------------------------------------
    | Local dev: shell hints on provision journey (APP_ENV=local only)
    |--------------------------------------------------------------------------
    |
    | docker-compose.ssh-dev.yml uses container name dply-ssh-dev by default.
    | Optional: URL of a self-hosted web terminal (e.g. ttyd) for ssh to the test host.
    |
    */
    'local_dev_ssh_compose_container' => env('DPLY_DEV_SSH_COMPOSE_CONTAINER', 'dply-ssh-dev'),

    'local_dev_web_terminal_url' => env('DPLY_DEV_SSH_WEB_TERMINAL_URL'),

];
