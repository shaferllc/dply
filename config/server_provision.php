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
    | Install python3-minimal + deploy the metrics snapshot script
    | during provision so freshly-built servers start collecting
    | CPU/RAM/disk data automatically. Disable to keep the install
    | behind the manual "Install Python for monitoring" service action.
    */
    'install_metrics_agent' => (bool) env('DPLY_SERVER_INSTALL_METRICS_AGENT', true),

    /*
    | Install the metrics agent INLINE during the bash provision script.
    | When false (default), the inline step is skipped and the install
    | runs over SSH after the journey completes via
    | InstallMetricsAgentJob — saves 30–60s on the journey wall-clock at
    | the cost of monitoring being unavailable for ~1 minute after the
    | journey reads "ready". Set DPLY_SERVER_INSTALL_METRICS_AGENT_INLINE=true
    | to force the inline behaviour back on.
    */
    'install_metrics_agent_inline' => (bool) env('DPLY_SERVER_INSTALL_METRICS_AGENT_INLINE', false),

    /*
    | Disable cloud-init's apt-daily / apt-daily-upgrade /
    | unattended-upgrades units at the very start of bash provisioning.
    | Without this, freshly-booted droplets often hold the dpkg lock
    | for 30–90s while cloud-init runs unattended-upgrades, and our
    | bootstrap politely waits before evicting (see dply_wait_for_apt_locks).
    | Pre-evicting up-front saves up to 90s of journey wall-clock on
    | clean boots; security drift is closed by Dply's recurring
    | maintenance scheduler instead.
    */
    'preempt_cloud_init_upgrades' => (bool) env('DPLY_PROVISION_PREEMPT_CLOUD_INIT_UPGRADES', true),

    /*
    | When true (default), mise installs Node / Python / Ruby from
    | prebuilt release binaries (MISE_*_COMPILE=0). Saves 90–240s for
    | Python/Ruby (which default to compile-from-source via
    | python-build / ruby-build) and is a no-op for Node (which already
    | defaults to binary). Set DPLY_MISE_PREFER_BINARY=false to fall
    | back to the legacy compile path.
    */
    'mise_prefer_binary' => (bool) env('DPLY_MISE_PREFER_BINARY', true),

    /*
    |--------------------------------------------------------------------------
    | Per-step ETA (server_provision_step_runs)
    |--------------------------------------------------------------------------
    |
    | The journey UI shows an "Avg X minutes (from N runs)" chip on the
    | active step + each pending step, computed from the org's previous
    | provisions in server_provision_step_runs. Below `step_eta_min_samples`
    | non-resumed rows the chip falls back to the static "Usually X" copy
    | so we don't display misleading averages from a single run.
    | Cached at the org+label_hash level for `step_eta_cache_ttl_seconds`.
    */
    'step_eta_min_samples' => max(1, (int) env('DPLY_STEP_ETA_MIN_SAMPLES', 3)),

    'step_eta_cache_ttl_seconds' => max(0, (int) env('DPLY_STEP_ETA_CACHE_TTL_SECONDS', 600)),

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
    | mise (multi-runtime version manager)
    |--------------------------------------------------------------------------
    |
    | When true (default), application + worker servers get mise installed
    | system-wide and activated for the deploy user during initial setup.
    | mise manages Node / Python / Ruby / Go versions per the multi-runtime
    | strategy; PHP stays on ondrej/php apt instead.
    |
    | Set DPLY_INSTALL_MISE_ON_PROVISION=false to skip on hosts that should
    | be PHP-only (or behind isolated networks where the mise apt repo is
    | unreachable).
    |
    */
    'install_mise_on_provision' => (bool) env('DPLY_INSTALL_MISE_ON_PROVISION', true),

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
