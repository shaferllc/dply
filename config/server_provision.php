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
    | Optional regional apt mirror (opt-in, off by default)
    |--------------------------------------------------------------------------
    | When set, provisioning rewrites the Ubuntu archive/security sources to
    | this mirror before the first apt update — provider regional mirrors are
    | far faster than archive.ubuntu.com. Examples:
    |   DigitalOcean: http://mirrors.digitalocean.com/ubuntu
    |   Hetzner:      http://mirror.hetzner.com/ubuntu/packages
    | Leave empty to keep the image's default sources. Set a wrong value and
    | apt can't fetch, so only enable a mirror you know serves your region.
    */
    'apt_mirror' => env('DPLY_APT_MIRROR', ''),

    /*
    |--------------------------------------------------------------------------
    | Boot-time head start (cloud-init user_data) — opt-in, off by default
    |--------------------------------------------------------------------------
    | When on, freshly created servers run a small head-start script via
    | cloud-init user_data at boot (apt update + base packages), overlapping the
    | time the control plane spends waiting for IP + SSH. The SSH'd provision
    | script then skip-fasts that work. SAFE TO LEAVE OFF: when off, no user_data
    | is injected and the bootstrap's cooperative wait is a no-op. Validate on a
    | throwaway droplet before enabling in prod (cloud-init timing is image-
    | dependent). Wired for DigitalOcean + Hetzner.
    */
    'boot_head_start' => (bool) env('DPLY_BOOT_HEAD_START', false),

    /*
    |--------------------------------------------------------------------------
    | Defer certbot install off the provision critical path — opt-in, off
    |--------------------------------------------------------------------------
    | When on, provisioning does NOT install certbot up front; the cert-issuance
    | path installs it on first use instead (the issuance builder always ensures
    | certbot is present, so this is correctness-preserving either way). Saves a
    | small amount of create-time. Off = current behavior (certbot at provision).
    */
    'defer_certbot' => (bool) env('DPLY_DEFER_CERTBOT', false),

    /*
    |--------------------------------------------------------------------------
    | Queue for server-provisioning jobs
    |--------------------------------------------------------------------------
    | Provisioning jobs (cloud create → poll IP → wait SSH → run setup) run on
    | this queue. Default 'dply' = same as today. Set to 'dply-provision' (which
    | Horizon already watches, at top priority) so a create doesn't wait behind
    | routine control-plane jobs. Only use a queue Horizon actually watches, or
    | the jobs will silently stall.
    */
    'queue' => env('DPLY_PROVISION_QUEUE', 'dply'),

    /*
    |--------------------------------------------------------------------------
    | Wait for SSH after cloud assigns a public IP (before stack setup)
    |--------------------------------------------------------------------------
    */
    // Tighter cadence (4s) so the setup script starts within a couple of probes
    // of sshd coming up, with the attempt count raised to preserve the same
    // ~360s worst-case window (90 × 4s).
    'ssh_ready_max_attempts' => max(5, (int) env('DPLY_SSH_READY_MAX_ATTEMPTS', 90)),

    'ssh_ready_retry_seconds' => max(3, (int) env('DPLY_SSH_READY_RETRY_SECONDS', 4)),

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
    | Deploy user Git identity (global git config on the server)
    |--------------------------------------------------------------------------
    |
    | Applied during provisioning when user.name / user.email are unset, and
    | editable from Manage → Tools → Git. Used for commits made on the server
    | (deploy hooks, manual git operations as the deploy user).
    |
    */
    'configure_deploy_git_identity' => filter_var(env('DPLY_CONFIGURE_DEPLOY_GIT_IDENTITY', true), FILTER_VALIDATE_BOOLEAN),

    'deploy_git_identity_name_suffix' => env('DPLY_DEPLOY_GIT_IDENTITY_NAME_SUFFIX', ' via Dply'),

    'deploy_git_identity_email_domain' => env('DPLY_DEPLOY_GIT_EMAIL_DOMAIN', 'dply.host'),

    'deploy_git_identity_email_local' => env('DPLY_DEPLOY_GIT_IDENTITY_EMAIL_LOCAL', 'deploy+{server_id}'),

    /*
    | Install python3-minimal + deploy the metrics snapshot script
    | during provision so freshly-built servers start collecting
    | CPU/RAM/disk data automatically. Disable to keep the install
    | behind the manual "Install Python for monitoring" service action.
    */
    'install_metrics_agent' => (bool) env('DPLY_SERVER_INSTALL_METRICS_AGENT', true),

    /*
    | Install the metrics agent INLINE during the bash provision script.
    | When true (default), the snapshot script + python3-minimal land as a
    | provision step and RunSetupScriptJob's success path writes the env +
    | crontab synchronously, so a freshly-built server starts collecting and
    | pushing metrics the moment the journey reads "ready" — no waiting on a
    | follow-up SSH job. Costs 30–60s of journey wall-clock for the apt +
    | deploy. Set DPLY_SERVER_INSTALL_METRICS_AGENT_INLINE=false to defer the
    | install to InstallMetricsAgentJob over SSH after the journey completes
    | (faster journey, monitoring unavailable for ~1 minute afterward).
    */
    'install_metrics_agent_inline' => (bool) env('DPLY_SERVER_INSTALL_METRICS_AGENT_INLINE', true),

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

    // Configure and enable OS-native automatic security updates
    // (unattended-upgrades) at the end of provisioning. The base bootstrap
    // preempts cloud-init's copy to avoid apt-lock contention during install;
    // this re-enables it with a security-only, no-auto-reboot policy.
    'install_unattended_upgrades' => (bool) env('DPLY_INSTALL_UNATTENDED_UPGRADES', true),

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
