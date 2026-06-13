<?php

declare(strict_types=1);

namespace App\Services\Servers\Concerns;

use App\Jobs\RunSetupScriptJob;
use App\Models\Server;
use App\Models\UserSshKey;
use App\Services\Servers\CaddySiteConfigBuilder;
use App\Services\Servers\HaproxyConfigBuilder;
use App\Services\Servers\ServerDeployGitIdentity;
use App\Services\Servers\ServerDeployLayoutBuilder;
use App\Services\Servers\ServerMetricsGuestScript;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait BuildsProvisionBootstrap
{


    /**
     * Install python3-minimal (root) and deploy the metrics snapshot
     * script under the deploy user's home so the post-provision env +
     * crontab step can wire up the push pipeline. Mirrors what the
     * "Install Python for monitoring" service action does, just inline
     * with first-run provision so a freshly-built server starts
     * collecting metrics without operator intervention.
     *
     * @return list<string>
     */
    private function metricsAgent(Server $server): array
    {
        if (! (bool) config('server_provision.install_metrics_agent', true)) {
            return [];
        }

        // Default: install the metrics agent inline so the server starts
        // collecting the moment the journey reads "ready" (RunSetupScriptJob's
        // success path then writes the env + crontab synchronously). Set
        // DPLY_SERVER_INSTALL_METRICS_AGENT_INLINE=false to defer the install
        // to InstallMetricsAgentJob over SSH after the journey completes —
        // shaves 30–60s off the journey wall-clock at the cost of monitoring
        // being unavailable for ~1 minute afterward.
        if (! (bool) config('server_provision.install_metrics_agent_inline', true)) {
            return [];
        }

        $deployUser = (string) config('server_provision.deploy_ssh_user', 'dply');
        if ($deployUser === '' || $deployUser === 'root' || ! preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $deployUser)) {
            return [];
        }

        try {
            $deployFragment = app(ServerMetricsGuestScript::class)->guestScriptDeployOnlyScript();
        } catch (\Throwable) {
            // Script file missing in the deployable tree — skip silently
            // rather than fail provision. Operators can still install
            // the agent via Services later.
            return [];
        }

        $apt = $this->ensurePackagesInstalled(
            ['python3-minimal'],
            '[dply] python3-minimal already installed; skipping package install.'
        );

        // Heredoc keeps the multi-line deploy script as a single
        // bash invocation under the deploy user; -H sets HOME so the
        // fragment's "$HOME/.dply/bin" lands in /home/<deploy-user>/.
        $heredoc = 'sudo -u '.escapeshellarg($deployUser).' -H bash -s <<\'DPLY_METRICS_DEPLOY\''."\n"
            .$deployFragment."\n"
            .'DPLY_METRICS_DEPLOY';

        return $this->withStep('Installing metrics agent', array_merge($apt, [$heredoc]));
    }

    /**
     * Create the deploy user with the same SSH key as root (from provisioning) so the app can
     * connect as that user after setup; root remains available on the image.
     *
     * The bootstrap-written authorized_keys also includes the server creator's
     * profile keys that are flagged `provision_on_new_servers = true`, so the
     * operator's laptop (etc.) lands on first boot instead of having to wait
     * for the post-provision authorized_keys sync. The matching panel rows
     * (ServerAuthorizedKey) are pre-created by
     * {@see RunSetupScriptJob::applyProvisionOutcomeToServer()} so
     * the workspace SSH Keys page reflects what's on the box from day zero —
     * a later Sync would otherwise rewrite authorized_keys to whatever the
     * panel currently knows and silently remove the bootstrap-installed keys.
     *
     * @return list<string>
     */
    private function dplyDeployUserBootstrap(Server $server): array
    {
        $username = (string) config('server_provision.deploy_ssh_user', 'dply');
        if ($username === '' || $username === 'root' || ! preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $username)) {
            return [];
        }

        $pub = $server->openSshPublicKeyFromPrivate();
        if ($pub === null || $pub === '') {
            return [];
        }

        $lines = [trim($pub)];
        foreach ($this->creatorProvisionableKeyLines($server) as $line) {
            $lines[] = $line;
        }
        $bundle = implode("\n", $lines)."\n";
        $b64 = base64_encode($bundle);

        $extraKeyCount = count($lines) - 1;

        // Each step inside is paired with a `[dply]` echo so the journey
        // log actually surfaces what happened. Without these, the step
        // panel was filled by the *next* step's preamble (bootstrap's
        // cloud-init pre-empt and force-reinstall echoes), which made
        // it look like "Creating server user" never ran the user creation.
        $userArg = escapeshellarg($username);

        return $this->withStep('Creating server user', [
            'echo '.escapeshellarg($b64).' | base64 -d > /tmp/dply-ssh-bootstrap.pub',
            'chmod 600 /tmp/dply-ssh-bootstrap.pub',
            'if id -u '.$userArg.' &>/dev/null; then',
            '  echo "[dply] user \"'.$username.'\" already exists; reusing"',
            'else',
            '  echo "[dply] creating user \"'.$username.'\" (sudo group, /bin/bash)"',
            '  useradd -m -s /bin/bash -G sudo '.$userArg,
            '  echo "[dply] user \"'.$username.'\" created"',
            'fi',
            'echo "[dply] installing SSH authorized_keys for \"'.$username.'\" (operational + '.$extraKeyCount.' profile key(s))"',
            'install -d -m 700 -o '.$userArg.' -g '.$userArg.' /home/'.$userArg.'/.ssh',
            'install -m 600 -o '.$userArg.' -g '.$userArg.' /tmp/dply-ssh-bootstrap.pub /home/'.$userArg.'/.ssh/authorized_keys',
            'rm -f /tmp/dply-ssh-bootstrap.pub',
            'echo "[dply] granting passwordless sudo to \"'.$username.'\" via /etc/sudoers.d/90-dply-user"',
            'printf \'%s\\n\' '.escapeshellarg($username.' ALL=(ALL) NOPASSWD:ALL').' > /etc/sudoers.d/90-dply-user',
            'chmod 440 /etc/sudoers.d/90-dply-user',
            'echo "[dply] server user \"'.$username.'\" ready (uid=$(id -u '.$userArg.'), groups=$(id -Gn '.$userArg.'))"',
        ]);
    }

    /**
     * Returns the cleaned `ssh-… AAAA… [comment]` public-key lines for the
     * server creator's UserSshKey rows that opt into new-server provisioning.
     * Silently skips malformed entries — the bootstrap should never write a
     * line that sshd would reject on first start.
     *
     * @return list<string>
     */
    private function creatorProvisionableKeyLines(Server $server): array
    {
        $creator = $server->user;
        if ($creator === null) {
            return [];
        }

        $rows = $creator->sshKeys()
            ->where('provision_on_new_servers', true)
            ->orderBy('name')
            ->get(['id', 'public_key']);

        $lines = [];
        foreach ($rows as $row) {
            $pk = trim((string) $row->public_key);
            if ($pk === '' || ! UserSshKey::publicKeyLooksValid($pk)) {
                continue;
            }
            $lines[] = $pk;
        }

        return $lines;
    }

    /** @return list<string> */
    private function bootstrap(): array
    {
        $lines = [
            'export DEBIAN_FRONTEND=noninteractive',
            // If a cloud-init boot head-start is mid-flight (BootHeadStartScript),
            // wait for it to finish (bounded) before we pre-empt/kill cloud-init
            // below — otherwise we'd cut off the apt warmup it's doing. No-op when
            // the head-start feature is off (markers never exist).
            'if [ -f /var/lib/dply/headstart.running ] && [ ! -f /var/lib/dply/headstart.done ]; then '
                .'echo "[dply] boot head-start in progress — waiting up to 240s for it to finish."; '
                .'for _ in $(seq 1 80); do [ -f /var/lib/dply/headstart.done ] && break; sleep 3; done; '
                .'fi',
        ];

        // Pre-empt cloud-init's apt-daily / unattended-upgrades AND
        // cloud-init's own modules on freshly-booted droplets. The
        // problem is two-layered:
        //   (a) apt-daily.timer / apt-daily-upgrade.timer / unattended-
        //       upgrades.service hold the dpkg lock for boot-time
        //       security upgrades.
        //   (b) cloud-init's own cloud-config.service and
        //       cloud-final.service run `apt-get update` AND can
        //       re-spawn apt mid-flight if we kill its child without
        //       stopping cloud-init itself.
        // Without (b), our previous attempt evicted unattended-upgrades
        // but cloud-init's own modules kept the lock — operators saw
        // "waited 0s — apt is busy" loop forever. Solution: stop
        // cloud-init.target FIRST (which transitively stops
        // cloud-config / cloud-final / the boot-time apt run), THEN
        // disable the auto-upgrade timers, THEN kill any leftover apt
        // children with SIGKILL so the lock is unconditionally free
        // before our first apt-get call.
        //
        // Drift from the security baseline is closed by Dply's
        // recurring maintenance scheduler instead. Toggle off via
        // DPLY_PROVISION_PREEMPT_CLOUD_INIT_UPGRADES=false if you want
        // cloud-init's auto-upgrade behaviour back.
        if ((bool) config('server_provision.preempt_cloud_init_upgrades', true)) {
            $lines = array_merge($lines, [
                'echo "[dply] preempting cloud-init upgrade activity (deferred to Dply scheduler)..."',
                // Halt cloud-init itself so its modules stop re-spawning apt.
                // cloud-init.target is the umbrella; stopping it cascades to
                // cloud-config / cloud-final / cloud-init-local.
                'systemctl stop cloud-init.target cloud-config.service cloud-final.service cloud-init.service cloud-init-local.service >/dev/null 2>&1 || true',
                // Then halt the auto-upgrade jobs. mask makes them
                // unstartable until explicitly unmasked.
                'systemctl stop unattended-upgrades.service apt-daily.timer apt-daily.service apt-daily-upgrade.timer apt-daily-upgrade.service >/dev/null 2>&1 || true',
                'systemctl disable unattended-upgrades.service apt-daily.timer apt-daily-upgrade.timer >/dev/null 2>&1 || true',
                'systemctl mask apt-daily.service apt-daily-upgrade.service >/dev/null 2>&1 || true',
                // Kill any apt children left behind by the systemctl stop
                // above (TERM first, then KILL after a short delay).
                'pkill -TERM -x unattended-upgr >/dev/null 2>&1 || true',
                'pkill -TERM -x apt-get >/dev/null 2>&1 || true',
                'pkill -TERM -x apt >/dev/null 2>&1 || true',
                'sleep 2',
                'pkill -KILL -x unattended-upgr >/dev/null 2>&1 || true',
                'pkill -KILL -x apt-get >/dev/null 2>&1 || true',
                'pkill -KILL -x apt >/dev/null 2>&1 || true',
                // dpkg lock files left behind by a SIGKILL stay until we
                // remove them — apt-get refuses to start otherwise. Safe
                // because we just killed every apt process above.
                'rm -f /var/lib/dpkg/lock-frontend /var/lib/dpkg/lock /var/lib/apt/lists/lock /var/cache/apt/archives/lock >/dev/null 2>&1 || true',
                // dpkg state may be half-configured if cloud-init was
                // mid-install when we killed it. Repair so the next
                // apt-get install doesn't trip on partially-installed
                // packages.
                'dpkg --configure -a >/dev/null 2>&1 || true',
            ]);
        }

        // Force-reinstall mode: wipe ALL step markers so every step
        // re-executes from scratch. Without this, after toggling
        // server_provision.force_reinstall=true the resume-skip would
        // happily fast-skip steps the operator explicitly wanted to
        // re-run.
        if ($this->forceReinstall()) {
            $lines[] = 'echo "[dply] force-reinstall mode — wiping step markers under /var/lib/dply/steps/."';
            $lines[] = 'rm -rf /var/lib/dply/steps';
        }

        $lines[] = $this->stepMarker('Installing system updates');

        return array_merge($lines, [
            // Force IPv4 for every apt call that follows. DigitalOcean
            // droplets (and most VPS providers) ship without working
            // IPv6 default routing, but apt resolves AAAA first and
            // burns ~30s timing out before falling back to v4 — and on
            // ppa.launchpadcontent.net the v4 fallback often races a
            // TLS handshake and bombs with "Cannot initiate the
            // connection ... (101: Network is unreachable)". Pinning
            // v4 globally via apt.conf.d sidesteps both issues for
            // every subsequent apt-get call (no per-call flag thread).
            'mkdir -p /etc/apt/apt.conf.d',
            // --force-unsafe-io skips the per-file fsync dpkg does during unpack
            // — safe on a fresh throwaway-until-ready provision and a meaningful
            // speedup on package-heavy installs (mysql/php). Install-Suggests off
            // avoids pulling optional suggested packages we never asked for.
            'printf \'Acquire::ForceIPv4 "true";\nAcquire::Retries "5";\nAcquire::http::Timeout "30";\nAcquire::https::Timeout "30";\nAPT::Install-Suggests "false";\nDpkg::Options:: "--force-unsafe-io";\n\' > /etc/apt/apt.conf.d/99dply',
            // Optional regional apt mirror (config: server_provision.apt_mirror).
            // Provider mirrors are much faster than archive.ubuntu.com. No-op when
            // unset. Rewrites both the classic sources.list and noble's deb822
            // ubuntu.sources. Must run before the first apt update below.
            'DPLY_APT_MIRROR='.escapeshellarg((string) config('server_provision.apt_mirror', '')),
            'if [ -n "${DPLY_APT_MIRROR}" ]; then echo "[dply] pointing apt sources at ${DPLY_APT_MIRROR}"; for _src in /etc/apt/sources.list /etc/apt/sources.list.d/ubuntu.sources; do [ -f "$_src" ] && sed -i -E "s@https?://(archive|security)\\.ubuntu\\.com/ubuntu@${DPLY_APT_MIRROR}@g" "$_src" || true; done; fi',
            // Silence needrestart. On Ubuntu 24.04 (noble) it's enabled
            // by default and runs after every dpkg install. Its
            // service-restart probe occasionally fails on non-interactive
            // installs (the daemon-detection logic was written for TTY
            // environments) and when that happens dpkg surfaces it as
            // "needrestart is being skipped since dpkg has failed
            //  E: Sub-process /usr/bin/dpkg returned an error code (1)"
            // — completely misleading because the actual install
            // succeeded; needrestart's POST hook is what bombed.
            // $nrconf{restart} = 'a' tells needrestart to auto-restart
            // without prompting; kernelhints = -1 suppresses the kernel
            // restart prompt. The Perl-style $nrconf{...} notation must
            // survive shell single-quote escaping, hence writeFile
            // (which does proper heredoc encoding) rather than printf.
            'mkdir -p /etc/needrestart/conf.d',
            $this->writeFileWithRollback('/etc/needrestart/conf.d/99-dply.conf', "\$nrconf{restart} = 'a';\n\$nrconf{kernelhints} = -1;\n"),
            // Ensure 2 GB of swap exists. Without this, small droplets
            // (the 1 GB / 458 MiB-available class) OOM during heavy
            // package installs:
            //   - mysql_install_db needs ~500 MB just to bootstrap
            //     the data dir on first install; on a 458 MiB droplet
            //     it dies instantly with no error code in dpkg's logs
            //   - PHP extension compiles, npm package builds, and
            //     unattended-upgrades all blow past available memory
            //     too on first boot
            // The kernel returning ENOMEM to a postinst usually
            // surfaces as "configure → half-configured in <1s" with no
            // helpful diagnostic — exactly what we kept hitting on
            // mysql-server-8.0. Swap eliminates the entire failure
            // class without any per-package workarounds. 2 GB is
            // plenty for first-boot peaks and still leaves disk
            // headroom on a 25 GB droplet.
            //
            // Idempotent: skips if /swapfile is already present AND
            // currently active in /proc/swaps. swapon is no-op if
            // already on. fallocate is fast (sparse) on most ext4 /
            // xfs setups; we follow with `dd` only if fallocate fails
            // (e.g., on tmpfs or unsupported filesystems).
            // Resource probe + low-memory mode. Heavy database
            // installs (mysql 8.0 needs ~500MB just to bootstrap its
            // data dir) reliably OOM on smaller droplets and leave
            // dpkg in a half-configured state. Rather than retry
            // forever or pretend the droplet is bigger, we measure
            // available RAM up front and either:
            //   - proceed with the full stack (≥1024MB total), with
            //     2GB of swap as a safety net for transient spikes
            //   - flip into "low-memory mode" (<1024MB total) where
            //     MySQL/Postgres are skipped, SQLite takes their
            //     place, and we surface a clear banner so the
            //     operator knows their wizard choice was overridden.
            //
            // DPLY_LOW_MEM is set in the script's environment so
            // every downstream install step can branch on it without
            // re-probing /proc/meminfo.
            'echo "[dply] probing droplet resources..."',
            'DPLY_TOTAL_MEM_MB=$(awk \'/MemTotal:/ {print int($2/1024)}\' /proc/meminfo)',
            'DPLY_AVAIL_MEM_MB=$(awk \'/MemAvailable:/ {print int($2/1024)}\' /proc/meminfo)',
            'echo "[dply] memory: total=${DPLY_TOTAL_MEM_MB}MB available=${DPLY_AVAIL_MEM_MB}MB"',
            'export DPLY_TOTAL_MEM_MB DPLY_AVAIL_MEM_MB',
            // The substitution banner is only meaningful when the
            // wizard's database pick is one we'd substitute (mysql/
            // postgres/mariadb). If the user already picked sqlite or
            // 'none', there's nothing to override — saying "we'll
            // install SQLite instead" reads as a non-sequitur.
            // Low-memory mode itself still flips on (we want swap and
            // any other downstream low-mem behaviour), but the
            // substitution-specific banner is gated.
            'if [ "${DPLY_TOTAL_MEM_MB:-0}" -lt 1024 ]; then',
            '  export DPLY_LOW_MEM=1',
            '  case "${DPLY_INSTALLED_DATABASE:-}" in',
            '    sqlite3|none|"")',
            '      echo "[dply] note: low-memory droplet (${DPLY_TOTAL_MEM_MB}MB total RAM). Swap will be provisioned for safety."',
            '      ;;',
            '    *)',
            '      echo ""',
            '      echo "[dply] ============================================================"',
            '      echo "[dply] LOW-MEMORY MODE ENGAGED"',
            '      echo "[dply] ------------------------------------------------------------"',
            '      echo "[dply] This droplet has ${DPLY_TOTAL_MEM_MB}MB total RAM, which is"',
            '      echo "[dply] below the 1GB threshold required to run MySQL 8.0 or"',
            '      echo "[dply] PostgreSQL safely. dply will install SQLite instead, and"',
            '      echo "[dply] keep Redis as the cache (Redis is lightweight)."',
            '      echo "[dply]"',
            '      echo "[dply] To run a full Laravel or WordPress stack with a real"',
            '      echo "[dply] database server, recommend re-provisioning on a 2GB+ droplet"',
            '      echo "[dply] (the s-1vcpu-2gb tier on DigitalOcean, or equivalent)."',
            '      echo "[dply] ============================================================"',
            '      echo ""',
            '      ;;',
            '  esac',
            'else',
            '  export DPLY_LOW_MEM=0',
            '  echo "[dply] memory threshold met (≥1024MB) — full stack will install."',
            'fi',
            // Always provision swap. On the happy path (≥1024MB
            // droplets) it absorbs transient spikes; in low-memory
            // mode it lets even SQLite + Redis + nginx + PHP-FPM
            // breathe under load. Idempotent.
            'echo "[dply] ensuring 2GB swap is provisioned..."',
            implode("\n", [
                'if [ -f /swapfile ] && swapon --show 2>/dev/null | grep -q "^/swapfile"; then',
                '  echo "[dply] /swapfile already active — skipping creation."',
                'else',
                // fallocate is fast (sparse) where supported; fall back
                // to dd on filesystems that reject it. The whole chain
                // is grouped so a failure short-circuits before we
                // touch /etc/fstab — the previous version had a
                // precedence bug where a fallocate+dd failure could
                // still trigger the fstab append via the trailing ||.
                '  if (fallocate -l 2G /swapfile 2>/dev/null || dd if=/dev/zero of=/swapfile bs=1M count=2048 status=progress) \\',
                '     && chmod 600 /swapfile \\',
                '     && mkswap /swapfile >/dev/null \\',
                '     && swapon /swapfile; then',
                '    if ! grep -q "^/swapfile" /etc/fstab; then',
                '      echo "/swapfile none swap sw 0 0" >> /etc/fstab',
                '    fi',
                '    echo "[dply] swap activated:"',
                '    free -h',
                '  else',
                '    echo "[dply] WARNING: swap creation failed — continuing without swap. Heavy installs (mysql, php-fpm extension compiles) may OOM on small droplets." >&2',
                '    rm -f /swapfile',
                '  fi',
                'fi',
            ]),
            // Block on cloud-init + any lingering apt-get / unattended-upgr
            // before the very first apt-get call. This is where bootstrap
            // most commonly raced cloud-init's auto-update on first boot
            // and bombed with "E: Could not get lock /var/lib/apt/lists/lock".
            // Helper is defined in the bootstrap preamble.
            'dply_wait_for_apt_locks',
            // Generate the system locale BEFORE we touch dpkg or
            // run any package postinst scripts. The DigitalOcean
            // cloud image ships with LANG=en_US.UTF-8 in
            // /etc/default/locale but doesn't run locale-gen, so
            // every postinst that honours LANG (mysql, perl,
            // debconf) bombs with "Cannot set LC_CTYPE to default
            // locale: No such file or directory". Worse, on Ubuntu
            // noble + mysql-server 8.4, postinst misreads the
            // locale-polluted stderr while probing daemon liveness
            // and surfaces it as MY-011065 — the postinst exits 1,
            // dpkg leaves the package half-configured, and every
            // subsequent retry trips on "E: Sub-process /usr/bin/dpkg
            // returned an error code (1)". Order matters: locale
            // first, THEN repair, THEN install.
            //
            // `locales` ships preinstalled on Ubuntu noble cloud
            // images, so locale-gen is callable directly here. We
            // reinstall the package later in the base-pkg list as a
            // defensive measure for minimal images where it might
            // not be present.
            'echo "[dply] generating en_US.UTF-8 locale..."',
            'locale-gen en_US.UTF-8 >/dev/null 2>&1 || true',
            'update-locale LANG=en_US.UTF-8 LC_ALL=en_US.UTF-8 >/dev/null 2>&1 || true',
            'export LANG=en_US.UTF-8 LC_ALL=en_US.UTF-8',
            // Pre-create /var/run/mysqld so any half-configured
            // mysql-server from a previous failed attempt has the
            // runtime dir it needs when dpkg --configure -a re-runs
            // its postinst. The mysql user may not exist yet (first
            // attempt) so we set ownership lazily; on a retry the
            // package is partially installed and the user does
            // exist, so chown succeeds.
            'install -d -m 0755 /var/run/mysqld',
            'chown mysql:mysql /var/run/mysqld 2>/dev/null || true',
            // Self-heal any half-configured dpkg state left behind by a
            // previous failed attempt (or our eviction kill, or
            // cloud-init being interrupted). Without this, every retry
            // re-fails on "E: Sub-process /usr/bin/dpkg returned an
            // error code (1) — N not fully installed or removed".
            // Now that locale + mysqld runtime dir are set up, the
            // repair has a chance of actually succeeding.
            'dply_repair_dpkg_state',
            'dply_apt_update',
            $this->stepMarker('Installing base packages'),
            'dply_wait_for_apt_locks',
            ...$this->ensurePackagesInstalled(
                ['ca-certificates', 'curl', 'git', 'gnupg', 'lsb-release', 'locales', 'software-properties-common', 'ufw', 'unattended-upgrades'],
                '[dply] base packages already installed; skipping package install.'
            ),
        ]);
    }

    /**
     * @return list<string>
     */
    private function deployGitIdentity(Server $server): array
    {
        $inner = app(ServerDeployGitIdentity::class)->bootstrapLinesForServer($server);
        if ($inner === []) {
            return [];
        }

        return $this->withStep('Configuring deploy user Git identity', $inner);
    }

    /**
     * @return list<string>
     */
    private function finalize(string $role): array
    {
        $lines = [];

        if (config('server_provision.install_fail2ban', true)) {
            $lines[] = $this->stepMarker('Installing Fail2ban');
            $lines = array_merge($lines, $this->ensurePackagesInstalled(
                ['fail2ban'],
                '[dply] fail2ban already installed; skipping package install.'
            ));
            $lines[] = 'systemctl enable --now fail2ban || true';
        }

        if (config('server_provision.install_unattended_upgrades', true)) {
            $lines[] = $this->stepMarker('Enabling automatic security updates');
            // The package ships in the base bootstrap; make sure it is present,
            // write our security-only policy, then (re)enable the apt timers.
            // Provisioning preempts cloud-init's copy earlier to avoid apt-lock
            // contention, so we own the final, enabled state here.
            $lines = array_merge($lines, $this->ensurePackagesInstalled(
                ['unattended-upgrades'],
                '[dply] unattended-upgrades already installed; skipping package install.'
            ));
            $lines[] = $this->writeFileWithRollback(
                '/etc/apt/apt.conf.d/20auto-upgrades',
                "APT::Periodic::Update-Package-Lists \"1\";\nAPT::Periodic::Unattended-Upgrade \"1\";\nAPT::Periodic::AutocleanInterval \"7\";\n"
            );
            // Sorts after the distro's 50unattended-upgrades so our keys win,
            // without clobbering (and being rollback-safe for) the original.
            $lines[] = $this->writeFileWithRollback(
                '/etc/apt/apt.conf.d/52dply-unattended-upgrades',
                $this->unattendedUpgradesPolicy()
            );
            $lines[] = 'systemctl enable --now unattended-upgrades.service >/dev/null 2>&1 || true';
            $lines[] = 'systemctl enable --now apt-daily.timer apt-daily-upgrade.timer >/dev/null 2>&1 || true';
        }

        $lines = array_merge($lines, $this->roleHardening($role));
        $lines[] = $this->stepMarker('Finalizing server');
        $lines[] = 'ufw --force enable || true';
        $lines[] = 'echo "[dply] provision finished"';

        return $lines;
    }

    /**
     * Security-only unattended-upgrades policy. Reboots stay manual (dply owns
     * maintenance windows); unused kernels and deps are cleaned up. The apt
     * `${distro_id}` / `${distro_codename}` variables are resolved by APT at
     * runtime — they are written verbatim (the content is base64-encoded, so
     * the shell never expands them).
     */
    private function unattendedUpgradesPolicy(): string
    {
        return <<<'CONF'
        Unattended-Upgrade::Allowed-Origins {
            "${distro_id}:${distro_codename}-security";
            "${distro_id}ESMApps:${distro_codename}-apps-security";
            "${distro_id}ESM:${distro_codename}-infra-security";
        };
        Unattended-Upgrade::Remove-Unused-Kernel-Packages "true";
        Unattended-Upgrade::Remove-Unused-Dependencies "true";
        Unattended-Upgrade::Automatic-Reboot "false";

        CONF;
    }

    /** @return list<string> */
    private function ufwSsh(): array
    {
        return $this->withStep('Configuring firewall', [
            'ufw allow OpenSSH',
        ]);
    }

    /**
     * @return array{root:string,app:string,releases:string,current:string,shared:string,logs:string,tmp:string,bin:string}
     */
    private function deployLayout(Server $server): array
    {
        return (new ServerDeployLayoutBuilder)->build($server);
    }

    /**
     * @param  array{web_root:string,logs:string,bin:string}  $layout
     * @return list<string>
     */
    private function createDeployLayout(array $layout): array
    {
        // Server-level dirs only. Per-site Capistrano layout (current /
        // shared / releases / tmp) is created at site-create time by
        // the site scaffolders under /home/dply/<site-slug>/, NOT by
        // server provisioning. The legacy "apps/<server-slug>" tree +
        // dply-prepare-layout systemd unit baked single-app-per-server
        // assumptions into the bare-server pipeline; both come out here.
        $homeDir = dirname($layout['web_root']); // /home/dply
        $webGroup = (string) config('site_settings.vm_site_file_web_group', 'www-data');

        return $this->withStep('Preparing server filesystem', [
            'install -d -m 0755 '.implode(' ', array_map('escapeshellarg', [
                $layout['web_root'],
                $layout['logs'],
            ])),
            // Render a dply-branded landing page so port 80 returns
            // something honest until the operator creates a site.
            'cat > '.escapeshellarg($layout['web_root'].'/index.html').' <<\'EOF\'
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>dply &middot; server ready</title>
<style>
:root{--ink:#171a0e;--moss:#5a6354;--mist:#8b9382;--cream:#f7f5ef;--line:rgba(23,26,14,.08);--forest:#1f3d2b;--sage:#7e9b5b;--gold:#c8a13a}
*{box-sizing:border-box}
html,body{height:100%}
body{margin:0;font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:var(--ink);background:radial-gradient(55rem 38rem at 88% -12%,rgba(126,155,91,.18),transparent 60%),radial-gradient(48rem 34rem at -12% 112%,rgba(200,161,58,.15),transparent 60%),linear-gradient(180deg,var(--cream),#fff 62%);display:flex;align-items:center;justify-content:center;padding:1.5rem;-webkit-font-smoothing:antialiased}
.card{width:100%;max-width:40rem;background:rgba(255,255,255,.72);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);border:1px solid var(--line);border-radius:1.5rem;box-shadow:0 1px 0 rgba(255,255,255,.7) inset,0 28px 64px -30px rgba(23,26,14,.3)}
.top{padding:2.4rem 2.4rem 0}
.row{display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-bottom:2rem}
.mark{display:inline-flex;align-items:center;gap:.6rem;font-weight:800;letter-spacing:-.03em;font-size:1.1rem}
.glyph{width:1.8rem;height:1.8rem;border-radius:.55rem;background:linear-gradient(150deg,var(--forest),#35684a);color:#eef3e8;display:inline-flex;align-items:center;justify-content:center;font-size:1.05rem;font-weight:800;box-shadow:0 8px 20px -10px rgba(31,61,43,.9)}
.pill{display:inline-flex;align-items:center;gap:.5rem;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.15em;color:var(--forest);background:rgba(126,155,91,.15);border:1px solid rgba(126,155,91,.32);padding:.42rem .72rem;border-radius:999px}
.live{position:relative;display:inline-block;width:.5rem;height:.5rem}
.live i{position:absolute;inset:0;border-radius:999px;background:var(--sage)}
.live i.p{animation:ping 1.6s cubic-bezier(0,0,.2,1) infinite}
@keyframes ping{75%,100%{transform:scale(2.6);opacity:0}}
h1{font-size:2.1rem;line-height:1.08;letter-spacing:-.03em;margin:.2rem 0 .65rem}
.sub{color:var(--moss);font-size:1.02rem;line-height:1.62;margin:0;max-width:33rem}
.term{margin:2rem 2.4rem 0}
.tbar{display:flex;align-items:center;gap:.45rem;padding:.7rem .85rem;background:#10160f;border:1px solid #0a0e09;border-bottom:0;border-radius:.9rem .9rem 0 0}
.tbar b{width:.62rem;height:.62rem;border-radius:999px;display:inline-block}
.tbar span{margin-left:auto;color:#5d6b58;font:600 .66rem/1 ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.14em;text-transform:uppercase}
.tbody{background:#0c110b;border:1px solid #0a0e09;border-radius:0 0 .9rem .9rem;padding:1rem 1.1rem;font:.86rem/1.7 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
.tbody .c{color:#5d6b58}
.tbody .p{color:#7e9b5b}
.tbody .cmd{color:#e9efe1}
.cur{display:inline-block;width:.55rem;height:1.05rem;background:#7e9b5b;vertical-align:-.15rem;margin-left:.15rem;animation:blink 1.1s steps(1) infinite}
@keyframes blink{50%{opacity:0}}
.steps{display:flex;flex-wrap:wrap;gap:.55rem;padding:1.6rem 2.4rem 0}
.chip{display:inline-flex;align-items:center;gap:.5rem;font-size:.82rem;font-weight:600;color:var(--ink);background:#fff;border:1px solid var(--line);border-radius:.7rem;padding:.55rem .8rem;box-shadow:0 1px 2px rgba(23,26,14,.04)}
.chip i{width:.5rem;height:.5rem;border-radius:999px;background:var(--sage)}
.foot{display:flex;align-items:center;justify-content:space-between;gap:1rem;margin-top:2rem;padding:1.1rem 2.4rem;border-top:1px solid var(--line);color:var(--mist);font-size:.78rem}
.foot a{color:var(--moss);text-decoration:none;font-weight:600}
@media (max-width:480px){.top,.term,.steps,.foot{padding-left:1.5rem;padding-right:1.5rem}h1{font-size:1.7rem}}
@media (prefers-color-scheme:dark){body{color:#eef3e8;background:radial-gradient(55rem 38rem at 88% -12%,rgba(126,155,91,.14),transparent 60%),linear-gradient(180deg,#0c110b,#0e140d)}.card{background:rgba(20,26,18,.72);border-color:rgba(255,255,255,.08);box-shadow:0 28px 64px -30px #000}h1{color:#f3f7ee}.sub{color:#aeb8a4}.chip{background:rgba(255,255,255,.04);color:#eef3e8;border-color:rgba(255,255,255,.08)}.foot{border-color:rgba(255,255,255,.08)}.pill{color:#aac083}}
</style>
</head>
<body>
<main class="card">
  <div class="top">
    <div class="row">
      <span class="mark"><span class="glyph">d</span>dply</span>
      <span class="pill"><span class="live"><i class="p"></i><i></i></span>Server ready</span>
    </div>
    <h1>Your server is provisioned.</h1>
    <p class="sub">It is wired up and listening on port 80 &mdash; there are just no sites on it yet. Spin up your first one from the dply dashboard, or straight from the CLI.</p>
  </div>
  <div class="term">
    <div class="tbar"><b style="background:#f1645c"></b><b style="background:#f5c14e"></b><b style="background:#5fcf80"></b><span>dply</span></div>
    <div class="tbody"><span class="c"># create your first site</span><br><span class="p">~ $</span> <span class="cmd">dply:site:create</span><span class="cur"></span></div>
  </div>
  <div class="steps">
    <span class="chip"><i></i>Connect a repository</span>
    <span class="chip"><i></i>Install WordPress</span>
    <span class="chip"><i></i>Start from blank</span>
  </div>
  <div class="foot"><span>Powered by dply</span><a href="https://dply.io">dply.io &rarr;</a></div>
</main>
</body>
</html>
EOF',
            // Owned by dply:dply since the docroot now lives under
            // /home/dply/. Nginx (running as www-data) needs read +
            // execute on the path; the dply user's home dir is
            // chmod 0755 + dply group is in www-data's reachable
            // group set, so traversal works. dply owns the bytes,
            // nginx serves them.
            'chown -R dply:dply '.escapeshellarg($layout['web_root']).' || true',
            'chmod 755 '.escapeshellarg($layout['web_root']),
            // The dply user's HOME must itself be traversable by the web server,
            // or every docroot under it 404s — nginx (www-data) / Caddy (caddy ∈
            // www-data) can't reach /home/dply/<site>/public. `useradd` can create
            // the home 0750 dply:dply (no traversal for the web group); set it to
            // 2750 dply:<webgroup> to match the deploy file model (see
            // RelocateSiteFilesJob), falling back to 0755 if the group is absent.
            'getent group '.escapeshellarg($webGroup).' >/dev/null 2>&1 '
                .'&& chgrp '.escapeshellarg($webGroup).' '.escapeshellarg($homeDir).' '
                .'&& chmod 2750 '.escapeshellarg($homeDir).' '
                .'|| chmod 0755 '.escapeshellarg($homeDir),
        ]);
    }

    /**
     * @param  array{web_root:string,logs:string,bin:string}  $layout
     * @return array<string, array{label:string,path:string,content:string}>
     */
    private function renderedConfigs(string $role, string $web, string $php, array $layout): array
    {
        $configs = [];
        $phpSocket = $php !== 'none' ? '/run/php/'.$this->phpStem($php).'-fpm.sock' : null;
        $webRoot = $layout['web_root'];

        if ($web === 'nginx') {
            $configs['nginx-starter'] = [
                'label' => 'Nginx default site',
                'path' => '/etc/nginx/sites-available/dply',
                'content' => <<<NGINX
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    root {$webRoot};
    index index.php index.html;

    location / {
        try_files \$uri \$uri/ =404;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:{$phpSocket};
    }
}
NGINX,
            ];
        }

        if ($web === 'caddy') {
            $configs['caddy-starter'] = [
                'label' => 'Caddy default site',
                'path' => '/etc/caddy/Caddyfile',
                'content' => (new CaddySiteConfigBuilder)->build($webRoot, $phpSocket),
            ];
        }

        if ($web === 'apache') {
            $configs['apache-starter'] = [
                'label' => 'Apache default site',
                'path' => '/etc/apache2/sites-available/dply.conf',
                'content' => <<<APACHE
<VirtualHost *:80>
    ServerName localhost
    DocumentRoot {$webRoot}
    <Directory {$webRoot}>
        AllowOverride All
        Require all granted
        DirectoryIndex index.php index.html
    </Directory>
    <FilesMatch \.php$>
        SetHandler "proxy:unix:{$phpSocket}|fcgi://localhost/"
    </FilesMatch>
</VirtualHost>
APACHE,
            ];
        }

        if ($web === 'openlitespeed') {
            $configs['openlitespeed-starter'] = [
                'label' => 'OpenLiteSpeed default site',
                'path' => '/usr/local/lsws/conf/vhosts/dply/vhconf.conf',
                'content' => "docRoot {$webRoot}/\nvhDomain localhost\nindex  {\n  useServer 0\n  indexFiles index.php, index.html\n}\n",
            ];
        }

        if ($web === 'traefik') {
            $configs['traefik-starter'] = [
                'label' => 'Traefik starter config',
                'path' => '/etc/traefik/traefik.yml',
                'content' => "entryPoints:\n  web:\n    address: \":80\"\nproviders:\n  file:\n    directory: \"/etc/traefik/dynamic\"\n    watch: true\n",
            ];
        }

        if (in_array($role, ['worker', 'plain', 'application'], true)) {
            $configs['supervisor-default'] = [
                'label' => 'Supervisor default worker',
                'path' => '/etc/supervisor/conf.d/dply-default-worker.conf',
                'content' => "[program:dply-default-worker]\ncommand=/bin/true\nautostart=false\nautorestart=false\nstdout_logfile={$layout['logs']}/worker.log\n",
            ];
        }

        if ($role === 'load_balancer') {
            $configs['haproxy-default'] = [
                'label' => 'HAProxy starter config',
                'path' => '/etc/haproxy/haproxy.cfg',
                'content' => (new HaproxyConfigBuilder)->build(),
            ];
        }

        // Docker hosts no longer get the per-server dply-prepare-layout
        // systemd unit — the per-app layout that unit prepared was the
        // single-app-per-server holdover. Docker container deploys
        // bring their own filesystem story; nothing to do here at
        // server-create time.

        return $configs;
    }

    /**
     * @param  array{web_root:string,logs:string,bin:string}  $layout
     * @return list<string>
     */
    private function writeRenderedConfigs(string $role, string $web, string $php, array $layout): array
    {
        $lines = [];

        foreach ($this->renderedConfigs($role, $web, $php, $layout) as $config) {
            $lines[] = $this->stepMarker('Writing '.$config['label']);
            $lines[] = $this->writeFileWithRollback($config['path'], $config['content']);

            if ($config['path'] === '/etc/nginx/sites-available/dply') {
                $lines[] = 'ln -sfn /etc/nginx/sites-available/dply /etc/nginx/sites-enabled/dply';
                $lines[] = 'rm -f /etc/nginx/sites-enabled/default';
                $lines[] = 'nginx -t && systemctl reload nginx';
            }

            if ($config['path'] === '/etc/caddy/Caddyfile') {
                $lines[] = 'systemctl reload caddy || systemctl restart caddy';
            }

            if ($config['path'] === '/etc/apache2/sites-available/dply.conf') {
                $lines[] = 'a2enmod proxy proxy_fcgi rewrite headers >/dev/null 2>&1 || true';
                $lines[] = 'a2ensite dply.conf';
                $lines[] = 'apachectl configtest';
                $lines[] = 'systemctl reload apache2 || service apache2 reload';
            }

            if ($config['path'] === '/usr/local/lsws/conf/vhosts/dply/vhconf.conf') {
                $lines[] = 'install -d -m 0755 /usr/local/lsws/conf/vhosts/dply';
                $lines[] = '/usr/local/lsws/bin/lswsctrl restart || true';
            }

            if ($config['path'] === '/etc/traefik/traefik.yml') {
                $lines[] = 'install -d -m 0755 /etc/traefik/dynamic /var/log/traefik';
                $lines[] = 'systemctl restart traefik';
            }

            if ($config['path'] === '/etc/haproxy/haproxy.cfg') {
                $lines[] = 'haproxy -c -f /etc/haproxy/haproxy.cfg';
                $lines[] = 'systemctl restart haproxy';
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function verificationCommands(string $role, string $web, string $php, string $database, string $cache): array
    {
        $lines = [$this->stepMarker('Running verification checks')];

        foreach ($this->verificationLabels($role, $web, $php, $database, $cache) as $label => $command) {
            $lines[] = 'if '.$command.' >/dev/null 2>&1; then echo '.escapeshellarg(self::VERIFY_PREFIX.$label.' :: ok :: Check passed').'; else echo '.escapeshellarg(self::VERIFY_PREFIX.$label.' :: failed :: Check failed').'; fi';
        }

        return $lines;
    }

    /**
     * @return array<string, string>
     */
    private function verificationLabels(string $role, string $web, string $php, string $database, string $cache): array
    {
        $checks = ['ufw' => 'ufw status'];

        if ($php !== 'none') {
            $checks['php'] = 'php -v';
            $checks['php-fpm'] = 'systemctl is-active '.$this->phpStem($php).'-fpm';
        }

        if ($web === 'nginx') {
            $checks['nginx'] = 'nginx -t';
        } elseif ($web === 'caddy') {
            $checks['caddy'] = 'caddy validate --config /etc/caddy/Caddyfile';
        } elseif ($web === 'apache') {
            $checks['apache'] = 'apachectl configtest';
        } elseif ($web === 'openlitespeed') {
            $checks['openlitespeed'] = '/usr/local/lsws/bin/lswsctrl status';
        } elseif ($web === 'traefik') {
            $checks['traefik'] = 'systemctl is-active traefik';
            $checks['caddy-backend'] = 'caddy validate --config /etc/caddy/Caddyfile';
        }

        if (str_starts_with($database, 'postgres')) {
            $checks['postgresql'] = 'systemctl is-active postgresql';
        } elseif ($database !== 'none' && $database !== 'sqlite3') {
            $checks['mysql'] = 'systemctl is-active mysql || systemctl is-active mariadb';
        }

        if ($role !== 'database') {
            if ($cache === 'redis') {
                $checks['redis'] = 'redis-cli ping';
            } elseif ($cache === 'valkey') {
                $checks['valkey'] = 'valkey-cli ping || redis-cli ping';
            } elseif ($cache === 'memcached') {
                $checks['memcached'] = 'systemctl is-active memcached';
            } elseif ($cache === 'keydb') {
                $checks['keydb'] = 'keydb-cli ping 2>/dev/null || redis-cli ping';
            } elseif ($cache === 'dragonfly') {
                $checks['dragonfly'] = 'systemctl is-active dragonfly && redis-cli ping';
            }
        }

        if ($role === 'load_balancer') {
            $checks['haproxy'] = 'haproxy -c -f /etc/haproxy/haproxy.cfg';
        }

        if ($role === 'docker') {
            $checks['docker'] = 'docker --version';
            $checks['docker-daemon'] = 'systemctl is-active docker';
        }

        return $checks;
    }
}
