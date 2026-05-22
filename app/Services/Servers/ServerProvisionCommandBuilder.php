<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Jobs\RunSetupScriptJob;
use App\Models\Server;
use App\Models\UserSshKey;

/**
 * Builds a bash script (list of lines) from servers.meta stack fields set at create time.
 *
 * Target images: Ubuntu 24.04 LTS (default DigitalOcean image) and compatible Debian/Ubuntu.
 */
final class ServerProvisionCommandBuilder
{
    private const STEP_PREFIX = '[dply-step] ';

    /**
     * Sibling marker emitted by {@see withStep()} once a step finishes.
     * Format: `[dply-step-end] <label>\t<seconds>` for normal completion,
     * or `[dply-step-end] <label>\t0\tresumed` for the resume-skip path.
     * Parsed by {@see App\Support\Servers\ProvisionStepDurations} into
     * server_provision_step_runs rows so the journey UI can render
     * data-driven ETAs ("Avg 1m 25s from 12 previous runs") in place
     * of the static "Usually a few minutes" copy.
     */
    private const STEP_END_PREFIX = '[dply-step-end] ';

    private const VERIFY_PREFIX = '[dply-verify] ';

    private const ROLLBACK_PREFIX = '[dply-rollback] ';

    /**
     * In-progress server, populated for the duration of a single build()
     * call so role helpers can read meta without threading it through
     * every signature. Reset to null on exit so a builder reused across
     * builds doesn't leak state.
     */
    private ?Server $server = null;

    /** @return list<string> */
    public function build(Server $server): array
    {
        $this->server = $server;
        try {
            return $this->buildInner($server);
        } finally {
            $this->server = null;
        }
    }

    /** @return list<string> */
    private function buildInner(Server $server): array
    {
        $meta = $server->meta ?? [];
        if (! is_array($meta) || empty($meta['server_role']) || ! is_string($meta['server_role'])) {
            return [];
        }

        $role = $meta['server_role'];
        if (! $this->isAllowed('server_roles', $role)) {
            return [];
        }

        $web = $this->coalesceId('webservers', $meta['webserver'] ?? null, 'nginx');
        $php = $this->coalesceId('php_versions', $meta['php_version'] ?? null, '8.3');
        $database = $this->coalesceId('databases', $meta['database'] ?? null, 'mysql84');
        $cache = $this->coalesceId('cache_services', $meta['cache_service'] ?? null, 'redis');
        $layout = $this->deployLayout($server);

        $lines = [];
        $lines[] = 'echo "[dply] provision start role='.$role.' web='.$web.' php='.$php.' db='.$database.' cache='.$cache.'"';

        // Stamp wizard inputs as environment defaults early. The
        // installed-stack emit at the end reads these, then live-probes
        // versions. Database is overwritten inside its install branch
        // (low-mem fallback flips it to sqlite3). PHP / webserver /
        // cache_service have no fallback paths today, so the wizard
        // value IS the installed value — we set them once here.
        $lines[] = 'export DPLY_INSTALLED_PHP_VERSION='.escapeshellarg($php);
        $lines[] = 'export DPLY_INSTALLED_WEBSERVER='.escapeshellarg($web);
        $lines[] = 'export DPLY_INSTALLED_CACHE_SERVICE='.escapeshellarg($cache);
        // Default; overridden inside the database install conditional.
        $lines[] = 'export DPLY_INSTALLED_DATABASE='.escapeshellarg($database);

        $lines = array_merge($lines, $this->dplyDeployUserBootstrap($server));
        $lines = array_merge($lines, $this->bootstrap());
        $lines = array_merge($lines, $this->createDeployLayout($layout));
        $lines = array_merge($lines, match ($role) {
            'application' => $this->roleApplication($web, $php, $database, $cache, $layout),
            'docker' => $this->roleDocker($web, $php, $database, $cache, $layout),
            'worker' => $this->roleWorker($php, $database, $layout),
            'database' => $this->roleDatabase($database, $layout),
            'redis' => $this->roleRedis(),
            'valkey' => $this->roleValkey(),
            'load_balancer' => $this->roleLoadBalancer($layout),
            'plain' => $this->rolePlain($layout),
            default => [],
        });

        $lines = array_merge($lines, $this->metricsAgent($server));
        $lines = array_merge($lines, $this->verificationCommands($role, $web, $php, $database, $cache));
        $lines = array_merge($lines, $this->finalize($role));
        $lines = array_merge($lines, $this->emitInstalledStack());

        return $lines;
    }

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

        // Default: skip inline metrics install. RunSetupScriptJob's
        // success path dispatches InstallMetricsAgentJob which runs the
        // same install bash via SSH after the journey reads "ready" —
        // shaves 30–60s off the journey wall-clock without losing the
        // capability. Override with DPLY_SERVER_INSTALL_METRICS_AGENT_INLINE=true.
        if (! (bool) config('server_provision.install_metrics_agent_inline', false)) {
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
     * @return list<array{type:string,key:string,label:string,content:string,metadata:array<string,mixed>}>
     */
    public function buildArtifacts(Server $server): array
    {
        $meta = $server->meta ?? [];
        if (! is_array($meta) || empty($meta['server_role']) || ! is_string($meta['server_role'])) {
            return [];
        }

        $role = $meta['server_role'];
        $web = $this->coalesceId('webservers', $meta['webserver'] ?? null, 'nginx');
        $php = $this->coalesceId('php_versions', $meta['php_version'] ?? null, '8.3');
        $database = $this->coalesceId('databases', $meta['database'] ?? null, 'mysql84');
        $cache = $this->coalesceId('cache_services', $meta['cache_service'] ?? null, 'redis');
        $layout = $this->deployLayout($server);

        $artifacts = [[
            'type' => 'deploy_layout',
            'key' => 'layout',
            'label' => 'Deploy layout',
            'content' => json_encode($layout, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
            'metadata' => ['role' => $role],
        ]];

        foreach ($this->renderedConfigs($role, $web, $php, $layout) as $key => $config) {
            $artifacts[] = [
                'type' => 'rendered_config',
                'key' => $key,
                'label' => $config['label'],
                'content' => $config['content'],
                'metadata' => ['path' => $config['path']],
            ];
        }

        $artifacts[] = [
            'type' => 'verification_plan',
            'key' => 'verification-plan',
            'label' => 'Verification plan',
            'content' => implode("\n", $this->verificationLabels($role, $web, $php, $database, $cache)),
            'metadata' => compact('role', 'web', 'php', 'database', 'cache'),
        ];

        $artifacts[] = [
            'type' => 'stack_summary',
            'key' => 'stack-summary',
            'label' => 'Installed stack',
            'content' => json_encode([
                'role' => $role,
                'webserver' => $web,
                'php_version' => $php,
                'database' => $database,
                'cache_service' => $cache,
                'deploy_user' => (string) config('server_provision.deploy_ssh_user', 'dply'),
                'expected_services' => array_keys($this->verificationLabels($role, $web, $php, $database, $cache)),
                'paths' => [
                    // Server-level paths only — per-site current/shared/releases
                    // get reported by each Site's own provisioning artifact.
                    'web_root' => $layout['web_root'],
                    'logs' => $layout['logs'],
                ],
                'config_files' => collect($this->renderedConfigs($role, $web, $php, $layout))
                    ->map(fn (array $config): string => $config['path'])
                    ->values()
                    ->all(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
            'metadata' => [
                'role' => $role,
                'webserver' => $web,
                'php_version' => $php,
                'database' => $database,
                'cache_service' => $cache,
                'deploy_user' => (string) config('server_provision.deploy_ssh_user', 'dply'),
                'expected_services' => array_keys($this->verificationLabels($role, $web, $php, $database, $cache)),
                'paths' => [
                    // Server-level paths only — per-site current/shared/releases
                    // get reported by each Site's own provisioning artifact.
                    'web_root' => $layout['web_root'],
                    'logs' => $layout['logs'],
                ],
                'config_files' => collect($this->renderedConfigs($role, $web, $php, $layout))
                    ->map(fn (array $config): string => $config['path'])
                    ->values()
                    ->all(),
            ],
        ];

        $artifacts[] = [
            'type' => 'rollback_plan',
            'key' => 'rollback-plan',
            'label' => 'Rollback plan',
            'content' => implode("\n", [
                'Restore config files written through dply_write_file.',
                'Disable generated systemd unit symlinks when they were created in this run.',
                'Remove new config files that did not exist before provisioning.',
            ]),
            'metadata' => ['strategy' => 'best_effort'],
        ];

        return $artifacts;
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
            'printf \'Acquire::ForceIPv4 "true";\nAcquire::Retries "5";\nAcquire::http::Timeout "30";\nAcquire::https::Timeout "30";\n\' > /etc/apt/apt.conf.d/99dply',
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
            'apt-get update -y',
            $this->stepMarker('Installing base packages'),
            'dply_wait_for_apt_locks',
            ...$this->ensurePackagesInstalled(
                ['ca-certificates', 'curl', 'gnupg', 'lsb-release', 'locales', 'software-properties-common', 'ufw', 'unattended-upgrades'],
                '[dply] base packages already installed; skipping package install.'
            ),
        ]);
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

        $lines = array_merge($lines, $this->roleHardening($role));
        $lines[] = $this->stepMarker('Finalizing server');
        $lines[] = 'ufw --force enable || true';
        $lines[] = 'echo "[dply] provision finished"';

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function roleApplication(string $web, string $php, string $database, string $cache, array $layout): array
    {
        $lines = [];
        $lines = array_merge($lines, $this->ufwSsh());
        $lines = array_merge($lines, $this->installWebserver($web));
        $lines = array_merge($lines, $this->installPhpIfNeeded($web, $php, $database));
        $lines = array_merge($lines, $this->installDatabaseIfNeeded($database));
        $lines = array_merge($lines, $this->installAppCache($cache));
        $lines = array_merge($lines, $this->maybeInstallSupervisor());
        $lines = array_merge($lines, $this->maybeInstallMise());
        $lines = array_merge($lines, $this->writeRenderedConfigs('application', $web, $php, $layout));

        if (config('server_provision.install_composer', true) && $php !== 'none') {
            $lines[] = $this->stepMarker('Installing Composer');
            $lines[] = $this->forceReinstall()
                ? 'curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer'
                : 'if command -v composer >/dev/null 2>&1; then echo "[dply] composer already installed; skipping installer."; else curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer; fi';
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function roleDocker(string $web, string $php, string $database, string $cache, array $layout): array
    {
        $lines = $this->withStep('Installing Docker', [
            ...$this->ensurePackagesInstalled(
                ['docker.io', 'docker-compose-v2'],
                '[dply] docker packages already installed; skipping package install.'
            ),
            'systemctl enable --now docker',
        ]);

        $lines[] = 'echo '.escapeshellarg(self::VERIFY_PREFIX.'docker :: ok :: Container host packages installed');

        return array_merge($lines, $this->writeRenderedConfigs('docker', $web, $php, $layout));
    }

    /**
     * @return list<string>
     */
    private function roleWorker(string $php, string $database, array $layout): array
    {
        $lines = [];
        $lines = array_merge($lines, $this->ufwSsh());
        $lines = array_merge($lines, $this->installPhpIfNeeded('none', $php, $database));
        $lines = array_merge($lines, $this->installDatabaseIfNeeded($database));
        $lines = array_merge($lines, $this->maybeInstallSupervisor());
        $lines = array_merge($lines, $this->maybeInstallMise());
        $lines = array_merge($lines, $this->writeRenderedConfigs('worker', 'none', $php, $layout));

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function roleDatabase(string $database, array $layout): array
    {
        $lines = [];
        $lines = array_merge($lines, $this->ufwSsh());
        $lines = array_merge($lines, $this->installDatabaseIfNeeded($database));
        $lines[] = 'ufw deny 3306/tcp || true';
        $lines[] = 'ufw deny 5432/tcp || true';
        $lines = array_merge($lines, $this->writeRenderedConfigs('database', 'none', 'none', $layout));

        return $lines;
    }

    /** @return list<string> */
    private function roleRedis(): array
    {
        $lines = $this->ufwSsh();
        $lines = array_merge($lines, $this->ensurePackagesInstalled(
            ['redis-server'],
            '[dply] redis-server already installed; skipping package install.'
        ));
        $lines[] = $this->writeFileWithRollback('/etc/redis/redis.conf', "bind 127.0.0.1 -::1\nprotected-mode yes\nmaxmemory 256mb\nmaxmemory-policy allkeys-lru\n");
        $lines[] = 'systemctl enable --now redis-server';

        return $lines;
    }

    /** @return list<string> */
    private function roleValkey(): array
    {
        $lines = $this->ufwSsh();
        $lines[] = 'if dpkg -s valkey-server >/dev/null 2>&1 || dpkg -s valkey >/dev/null 2>&1; then echo "[dply] valkey already installed; skipping package install."; else apt-get install -y --no-install-recommends valkey-server || apt-get install -y --no-install-recommends valkey; fi';
        $lines[] = $this->writeFileWithRollback('/etc/valkey/valkey.conf', "bind 127.0.0.1 ::1\nprotected-mode yes\nmaxmemory 256mb\nmaxmemory-policy allkeys-lru\n");
        $lines[] = 'systemctl enable --now valkey-server 2>/dev/null || systemctl enable --now valkey 2>/dev/null || true';

        return $lines;
    }

    /** @return list<string> */
    private function roleLoadBalancer(array $layout): array
    {
        $lines = $this->ufwSsh();
        $lines = array_merge($lines, $this->ensurePackagesInstalled(
            ['haproxy', 'certbot'],
            '[dply] haproxy and certbot already installed; skipping package install.'
        ));
        $lines = array_merge($lines, $this->writeRenderedConfigs('load_balancer', 'none', 'none', $layout));
        $lines[] = 'systemctl enable --now haproxy';
        $lines[] = 'ufw allow 80/tcp';
        $lines[] = 'ufw allow 443/tcp';

        return $lines;
    }

    /** @return list<string> */
    private function rolePlain(array $layout): array
    {
        $lines = $this->ufwSsh();
        $lines = array_merge($lines, $this->maybeInstallSupervisor());
        $lines = array_merge($lines, $this->writeRenderedConfigs('plain', 'none', 'none', $layout));

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function maybeInstallSupervisor(): array
    {
        if (! config('server_provision.install_supervisor_on_provision', false)) {
            return [];
        }

        return [
            $this->stepMarker('Installing Supervisor'),
            ...$this->ensurePackagesInstalled(
                ['supervisor'],
                '[dply] supervisor already installed; skipping package install.'
            ),
            'systemctl enable --now supervisor',
        ];
    }

    /**
     * Install mise system-wide via apt, activate it for the deploy user,
     * and pin any per-runtime defaults the wizard recorded on the server.
     *
     * mise manages non-PHP runtimes (Node / Python / Ruby / Go) per the
     * multi-runtime strategy. The polyglot-host preset (or any wizard
     * preset that pre-selects runtimes) writes a `runtime_defaults` map
     * onto the server's meta:
     *
     *   meta.runtime_defaults = ['node' => '22', 'python' => '3.12', ...]
     *
     * Each entry becomes a `mise use --global <tool>@<ver>` line so a
     * site without its own pin (no .tool-versions, no Site-level
     * runtime_version) gets the server default at deploy time.
     *
     * @return list<string>
     */
    private function maybeInstallMise(): array
    {
        if (! config('server_provision.install_mise_on_provision', true)) {
            return [];
        }

        $mise = app(MiseInstallScriptBuilder::class);
        $deployUser = (string) config('server_provision.deploy_ssh_user', 'dply');

        $lines = array_merge(
            [$this->stepMarker('Installing mise (Node / Python / Ruby / Go manager)')],
            $mise->installLines($this->forceReinstall()),
            $mise->activateForUserLines($deployUser),
        );

        $defaults = $this->serverRuntimeDefaults();
        foreach ($defaults as $runtime => $version) {
            $lines = array_merge(
                $lines,
                $mise->installRuntimeForUserLines($deployUser, $runtime, $version),
            );
        }

        return $lines;
    }

    /**
     * Pull the wizard-defined per-runtime defaults from the server meta.
     * Returns an empty array when nothing was recorded — mise still
     * installs but no specific runtime versions are pinned globally.
     *
     * @return array<string, string>
     */
    private function serverRuntimeDefaults(): array
    {
        $meta = $this->server?->meta ?? [];
        if (! is_array($meta)) {
            return [];
        }
        $defaults = $meta['runtime_defaults'] ?? null;
        if (! is_array($defaults)) {
            return [];
        }

        $clean = [];
        foreach ($defaults as $runtime => $version) {
            if (! is_string($runtime) || ! is_string($version)) {
                continue;
            }
            $version = trim($version);
            if ($version === '') {
                continue;
            }
            $clean[$runtime] = $version;
        }

        return $clean;
    }

    /** @return list<string> */
    private function ufwSsh(): array
    {
        return $this->withStep('Configuring firewall', [
            'ufw allow OpenSSH',
        ]);
    }

    /**
     * @return list<string>
     */
    private function installAppCache(string $cache): array
    {
        return match ($cache) {
            'none' => [],
            'valkey' => $this->withStep('Installing Valkey', [
                'if dpkg -s valkey-server >/dev/null 2>&1 || dpkg -s valkey >/dev/null 2>&1; then echo "[dply] valkey already installed; skipping package install."; else apt-get install -y --no-install-recommends valkey-server || apt-get install -y --no-install-recommends valkey; fi',
                $this->writeFileWithRollback('/etc/valkey/valkey.conf', "bind 127.0.0.1 ::1\nmaxmemory 256mb\nmaxmemory-policy allkeys-lru\n"),
                'systemctl enable --now valkey-server 2>/dev/null || systemctl enable --now valkey 2>/dev/null || true',
            ]),
            'memcached' => $this->withStep('Installing Memcached', [
                ...$this->ensurePackagesInstalled(
                    ['memcached', 'libmemcached-tools'],
                    '[dply] memcached already installed; skipping package install.'
                ),
                $this->writeFileWithRollback('/etc/memcached.conf', "-d\nlogfile /var/log/memcached.log\n-m 256\n-p 11211\n-l 127.0.0.1\n-U 0\n"),
                'systemctl enable --now memcached',
            ]),
            'keydb' => $this->installKeyDb(),
            'dragonfly' => $this->installDragonfly(),
            default => $this->withStep('Installing Redis', [
                ...$this->ensurePackagesInstalled(
                    ['redis-server'],
                    '[dply] redis-server already installed; skipping package install.'
                ),
                $this->writeFileWithRollback('/etc/redis/redis.conf', "bind 127.0.0.1 -::1\nmaxmemory 256mb\nmaxmemory-policy allkeys-lru\n"),
                'systemctl enable --now redis-server',
            ]),
        };
    }

    /**
     * Install KeyDB from the project's launchpad PPA. Drop-in Redis replacement;
     * provides redis-cli compatibility and a `keydb-server` systemd unit.
     *
     * @return list<string>
     */
    private function installKeyDb(): array
    {
        return $this->withStep('Installing KeyDB', [
            'if dpkg -s keydb-server >/dev/null 2>&1 || dpkg -s keydb >/dev/null 2>&1; then echo "[dply] keydb already installed; skipping repository + package install."; else '
                .'apt-get install -y --no-install-recommends software-properties-common ca-certificates && '
                .'add-apt-repository -y ppa:eq-alpha/keydb 2>/dev/null || true; '
                .'apt-get update -y && '
                .'apt-get install -y --no-install-recommends keydb-server keydb-tools; fi',
            $this->writeFileWithRollback('/etc/keydb/keydb.conf', "bind 127.0.0.1 ::1\nprotected-mode yes\nmaxmemory 256mb\nmaxmemory-policy allkeys-lru\nport 6379\n"),
            'systemctl enable --now keydb-server 2>/dev/null || systemctl enable --now keydb 2>/dev/null || true',
        ]);
    }

    /**
     * Install Dragonfly from the official apt repo. Wire-compatible with Redis on port 6379;
     * verified with redis-cli.
     *
     * @return list<string>
     */
    private function installDragonfly(): array
    {
        return $this->withStep('Installing Dragonfly', [
            'if dpkg -s dragonfly >/dev/null 2>&1; then echo "[dply] dragonfly already installed; skipping repository + package install."; else '
                .'install -d /etc/apt/keyrings && '
                .'curl -fsSL https://packages.dragonflydb.io/keys/release.asc | gpg --dearmor --yes -o /etc/apt/keyrings/dragonfly.gpg && '
                .'chmod 0644 /etc/apt/keyrings/dragonfly.gpg && '
                .'. /etc/os-release && '
                .'echo "deb [signed-by=/etc/apt/keyrings/dragonfly.gpg] https://packages.dragonflydb.io/dragonfly/ubuntu ${VERSION_CODENAME:-jammy} main" > /etc/apt/sources.list.d/dragonfly.list && '
                .'apt-get update -y && '
                .'apt-get install -y --no-install-recommends dragonfly; fi',
            // Dragonfly ships its own systemd unit; just ensure it's enabled.
            'systemctl enable --now dragonfly 2>/dev/null || true',
        ]);
    }

    /**
     * @return list<string>
     */
    private function installWebserver(string $web): array
    {
        if ($web === 'none') {
            return [];
        }

        $lines = [];

        if ($web === 'nginx') {
            $lines[] = $this->stepMarker('Installing webserver');
            $lines = array_merge($lines, $this->ensurePackagesInstalled(
                ['nginx'],
                '[dply] nginx already installed; skipping package install.'
            ));
            $lines[] = 'ufw allow "Nginx Full"';
            $lines[] = 'systemctl enable --now nginx';
            $lines = array_merge($lines, $this->certbotForWeb($web));
        } elseif ($web === 'apache') {
            $lines[] = $this->stepMarker('Installing webserver');
            $lines = array_merge($lines, $this->ensurePackagesInstalled(
                ['apache2'],
                '[dply] apache2 already installed; skipping package install.'
            ));
            $lines[] = 'ufw allow "Apache Full"';
            $lines[] = 'systemctl enable --now apache2';
            $lines = array_merge($lines, $this->certbotForWeb($web));
        } elseif ($web === 'openlitespeed') {
            $lines[] = $this->stepMarker('Installing webserver');
            $lines[] = 'wget -qO - https://repo.litespeed.sh | bash';
            $lines[] = 'apt-get update -y';
            $lines[] = 'apt-get install -y --no-install-recommends openlitespeed';
            $lines[] = 'ufw allow 80/tcp';
            $lines[] = 'ufw allow 443/tcp';
            $lines[] = '/usr/local/lsws/bin/lswsctrl start || true';
        } elseif ($web === 'caddy') {
            $lines[] = $this->stepMarker('Installing webserver');
            $lines[] = 'install -d /usr/share/keyrings';
            $lines[] = 'curl -fsSL https://dl.cloudsmith.io/public/caddy/stable/gpg.key | gpg --batch --yes --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg';
            $lines[] = 'curl -fsSL https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt | tee /etc/apt/sources.list.d/caddy-stable.list';
            $lines[] = 'apt-get update -y';
            $lines[] = 'apt-get install -y --no-install-recommends caddy';
            $lines[] = 'ufw allow 80/tcp';
            $lines[] = 'ufw allow 443/tcp';
            $lines[] = 'systemctl enable --now caddy';
        } elseif ($web === 'traefik') {
            $lines[] = $this->stepMarker('Installing webserver');
            $lines[] = 'apt-get install -y --no-install-recommends traefik caddy';
            $lines[] = 'install -d -m 0755 /etc/traefik/dynamic /etc/caddy/sites-enabled /var/log/traefik';
            $lines[] = $this->writeFileWithRollback('/etc/traefik/traefik.yml', "entryPoints:\n  web:\n    address: \":80\"\nproviders:\n  file:\n    directory: \"/etc/traefik/dynamic\"\n    watch: true\nlog:\n  filePath: \"/var/log/traefik/traefik.log\"\naccessLog:\n  filePath: \"/var/log/traefik/access.log\"\n");
            $lines[] = 'ufw allow 80/tcp';
            $lines[] = 'ufw allow 443/tcp';
            $lines[] = 'systemctl enable --now caddy';
            $lines[] = 'systemctl enable --now traefik';
        }

        return $lines;
    }

    /** @return list<string> */
    private function certbotForWeb(string $web): array
    {
        if ($web === 'nginx') {
            return $this->ensurePackagesInstalled(
                ['certbot', 'python3-certbot-nginx'],
                '[dply] nginx certbot packages already installed; skipping package install.'
            );
        }
        if ($web === 'apache') {
            return $this->ensurePackagesInstalled(
                ['certbot', 'python3-certbot-apache'],
                '[dply] apache certbot packages already installed; skipping package install.'
            );
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function installPhpIfNeeded(string $web, string $php, string $database): array
    {
        if ($php === 'none') {
            return [];
        }

        $stem = $this->phpStem($php);
        $lines = [
            $this->stepMarker('Installing PHP '.$php),
            ...$this->ensureOndrejPhpRepository(),
        ];

        $pkgs = [
            $stem.'-cli',
            $stem.'-fpm',
            $stem.'-common',
            $stem.'-mbstring',
            $stem.'-xml',
            $stem.'-curl',
            $stem.'-zip',
            $stem.'-intl',
            $stem.'-bcmath',
            $stem.'-opcache',
        ];

        if (str_starts_with($database, 'postgres')) {
            $pkgs[] = $stem.'-pgsql';
        } elseif (in_array($database, ['mysql84', 'mysql80', 'mysql57', 'mariadb114', 'mariadb11', 'mariadb1011'], true)) {
            $pkgs[] = $stem.'-mysql';
        } elseif ($database === 'sqlite3') {
            $pkgs[] = $stem.'-sqlite3';
        } else {
            $pkgs[] = $stem.'-mysql';
        }

        $lines = array_merge($lines, $this->ensurePackagesInstalled(
            $pkgs,
            '[dply] PHP packages already installed; skipping package install.'
        ));

        // apt-get respects /usr/sbin/policy-rc.d during install (returns 101 → don't start services),
        // so php-fpm ends up enabled but inactive. Explicitly start it the same way nginx/mysql/redis
        // are started; the verification check `systemctl is-active php{ver}-fpm` then passes.
        $lines[] = 'systemctl enable --now '.$stem.'-fpm';

        $lines = array_merge($lines, $this->wireWebserverToPhp($web, $php));

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function wireWebserverToPhp(string $web, string $php): array
    {
        if ($web === 'none' || $php === 'none') {
            return [];
        }

        if ($web === 'apache') {
            $stem = $this->phpStem($php);

            return [
                'apt-get install -y --no-install-recommends libapache2-mod-'.$stem,
                'a2enmod '.$stem,
                'systemctl reload apache2',
            ];
        }

        if ($web === 'openlitespeed') {
            return [
                'apt-get install -y --no-install-recommends lsphp'.str_replace('.', '', $php).' lsphp'.str_replace('.', '', $php).'-mysql lsphp'.str_replace('.', '', $php).'-pgsql || true',
                '/usr/local/lsws/bin/lswsctrl restart || true',
            ];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    /**
     * Public wrapper around the private install-database flow. Lets ad-hoc
     * server-management tooling (the dply:server:add-engine flow, on-demand
     * installer actions) reuse the same shell content the bootstrap path
     * emits, without duplicating the per-engine package map or the
     * idempotent install guards.
     *
     * @return list<string>
     */
    public function installEngineLines(string $engineId): array
    {
        return $this->installDatabaseIfNeeded($engineId);
    }

    private function installDatabaseIfNeeded(string $database): array
    {
        if ($database === 'none') {
            return [];
        }

        if (str_starts_with($database, 'postgres')) {
            return $this->installPostgresql($database);
        }

        if (str_starts_with($database, 'mysql') || $database === 'sqlite3') {
            if ($database === 'sqlite3') {
                // User explicitly picked sqlite — DPLY_INSTALLED_DATABASE matches.
                return $this->withStep('Installing SQLite', [
                    'apt-get install -y --no-install-recommends sqlite3 libsqlite3-0',
                    'export DPLY_INSTALLED_DATABASE="sqlite3"',
                ]);
            }

            return $this->withStep('Installing MySQL', $this->installMysqlSequence($database));
        }

        if (str_starts_with($database, 'mariadb')) {
            return $this->withStep('Installing MariaDB', [
                ...$this->ensurePackagesInstalled(
                    ['mariadb-server'],
                    '[dply] mariadb-server already installed; skipping package install.'
                ),
                'export DPLY_INSTALLED_DATABASE='.escapeshellarg($database),
                $this->writeFileWithRollback('/etc/mysql/mariadb.conf.d/99-dply.cnf', "[mysqld]\nbind-address = 127.0.0.1\nmax_connections = 200\ninnodb_buffer_pool_size = 256M\n"),
                'systemctl enable --now mariadb',
                'systemctl restart mariadb || true',
            ]);
        }

        return [];
    }

    /**
     * Emit the reconciled installed-stack snapshot at the very end of
     * the script. Single tagged JSON line that the dply-side observer
     * picks up via `InstalledStack::parseFromOutput()` and persists to
     * `server.meta.installed_stack`.
     *
     * Reads:
     *   - DPLY_INSTALLED_DATABASE / _PHP_VERSION / _WEBSERVER /
     *     _CACHE_SERVICE  — set by the install conditionals (the script
     *     knows what it tried to install based on its own branches; no
     *     need to re-detect)
     *   - DPLY_TOTAL_MEM_MB / _LOW_MEM  — set by the bootstrap probe
     *
     * Probes live (apt picks the version at runtime, we can't bake it
     * in at build time):
     *   - database version via the engine's CLI (mysqladmin --version,
     *     psql --version, sqlite3 --version)
     *   - swap MB via swapon --show
     *
     * Builds the JSON with printf so we don't take a jq dependency on
     * the droplet just for one tagged line. Fields use the same snake_
     * case keys as InstalledStack::toArray().
     *
     * @return list<string>
     */
    private function emitInstalledStack(): array
    {
        return [
            'echo "[dply] reconciling installed stack..."',
            implode("\n", [
                // Detect database version live from the running engine.
                'DPLY_INSTALLED_DATABASE_VERSION=""',
                'case "${DPLY_INSTALLED_DATABASE:-}" in',
                '  mysql*|mariadb*)',
                '    DPLY_INSTALLED_DATABASE_VERSION=$(mysqladmin --version 2>/dev/null \\',
                '      | sed -n \'s/.*Distrib \([0-9.]*\).*/\1/p\')',
                '    ;;',
                '  postgres*)',
                '    DPLY_INSTALLED_DATABASE_VERSION=$(psql --version 2>/dev/null | awk \'{print $3}\')',
                '    ;;',
                '  sqlite*)',
                '    DPLY_INSTALLED_DATABASE_VERSION=$(sqlite3 --version 2>/dev/null | awk \'{print $1}\')',
                '    ;;',
                'esac',
                // Sum active swap (in MB).
                'DPLY_INSTALLED_SWAP_MB=$(swapon --show=size --bytes --noheadings 2>/dev/null \\',
                '  | awk \'{s+=$1} END {if (s>0) print int(s/1024/1024); else print 0}\')',
                // JSON booleans (true/false) and numbers (no quotes) need
                // distinct printf format strings — strings are quoted,
                // numbers and booleans are not. Hence the deliberately
                // explicit format string below.
                'if [ "${DPLY_LOW_MEM:-0}" = "1" ]; then DPLY_INSTALLED_LOW_MEM_JSON=true; else DPLY_INSTALLED_LOW_MEM_JSON=false; fi',
                'printf \'[dply-installed-stack] {"database":"%s","database_version":"%s","php_version":"%s","webserver":"%s","cache_service":"%s","low_mem_mode":%s,"total_memory_mb":%s,"swap_mb":%s}\n\' \\',
                '  "${DPLY_INSTALLED_DATABASE:-}" \\',
                '  "${DPLY_INSTALLED_DATABASE_VERSION:-}" \\',
                '  "${DPLY_INSTALLED_PHP_VERSION:-}" \\',
                '  "${DPLY_INSTALLED_WEBSERVER:-}" \\',
                '  "${DPLY_INSTALLED_CACHE_SERVICE:-}" \\',
                '  "${DPLY_INSTALLED_LOW_MEM_JSON}" \\',
                '  "${DPLY_TOTAL_MEM_MB:-null}" \\',
                '  "${DPLY_INSTALLED_SWAP_MB:-0}"',
            ]),
        ];
    }

    /**
     * Defensive MySQL install for Ubuntu noble + mysql-server-8.0.
     *
     * The vanilla `apt-get install mysql-server` path was fragile: the
     * postinst calls `mysql_install_db` (which needs ~400-500 MB RAM
     * to bootstrap) and then auto-starts mysqld via systemd, all in a
     * single dpkg transaction. On smaller droplets (1 GB) with cloud-
     * init still resident the data-dir init OOMs, postinst exits 1,
     * dpkg leaves the package half-configured, and every retry hits
     * the same ceiling. Even on roomier droplets, postinst races
     * tmpfiles.d on first boot — `/var/run/mysqld` doesn't exist yet
     * — and mysqld reports MY-011065 "Unable to determine if daemon
     * is running: Invalid argument (rc=0)" before exiting.
     *
     * The new sequence separates "unpack the package" from "start the
     * daemon" so each can be diagnosed and retried independently:
     *
     *   1. Drop a small mysql config (innodb_buffer_pool_size = 64M)
     *      so mysql_install_db's bootstrap stays inside even a 1 GB
     *      droplet's free RAM. Bumped to a sensible production value
     *      after the daemon comes up.
     *   2. Pre-create /var/run/mysqld with the right ownership.
     *   3. Install policy-rc.d shim that returns 101 — tells dpkg-
     *      level service starters "do not invoke this service". This
     *      keeps mysql.service from being kicked off during postinst.
     *   4. apt-get install mysql-server. Postinst still runs
     *      mysql_install_db (which is what we actually need), but
     *      skips the systemctl start.
     *   5. Remove the policy-rc.d shim.
     *   6. Start mysql.service ourselves and verify the socket.
     *      Failure here is captured cleanly via journalctl rather
     *      than getting buried in dpkg's status transitions.
     *   7. Bump innodb_buffer_pool_size to 256M for steady-state.
     *
     * @return list<string>
     */
    private function installMysqlSequence(string $wizardDatabase): array
    {
        // Low-memory escape hatch wraps the whole MySQL sequence.
        // On droplets with <1GB total RAM, mysql_install_db OOMs
        // during data-dir bootstrap (~500 MB peak) and leaves dpkg
        // in a wedged state we can't reliably recover from. Falling
        // back to SQLite is the difference between "provisioning
        // failed" and "provisioning succeeded with a more modest
        // stack." The wizard's database choice is preserved in
        // server.meta — it just isn't what physically got installed
        // on this hardware.
        $sqliteFallback = [
            'echo "[dply] LOW-MEMORY MODE: skipping MySQL install — droplet has only ${DPLY_TOTAL_MEM_MB}MB RAM."',
            'echo "[dply] Installing SQLite as a substitute. Laravel/WordPress sites will use SQLite for development;"',
            'echo "[dply] re-provision on a 2GB+ droplet to switch to MySQL."',
            'apt-get install -y --no-install-recommends sqlite3 libsqlite3-0',
            'echo "[dply] SQLite installed in low-memory mode."',
            // Reconciliation marker: the snapshot at end-of-script
            // emits this value, which is the truth — wizard wanted
            // mysql but reality is sqlite3.
            'export DPLY_INSTALLED_DATABASE="sqlite3"',
        ];

        $mysqlInstall = [
            // Pre-create the runtime dir; ownership is fixed up after the
            // mysql user is created by the package install.
            'install -d -m 0755 /var/run/mysqld',
            // Conservative init config — written BEFORE install so
            // mysql_install_db reads it during bootstrap. 64M buffer
            // pool keeps init memory under 256 MB total even on a
            // 1 GB droplet that's still hosting cloud-init.
            $this->writeFileWithRollback('/etc/mysql/mysql.conf.d/00-dply-init.cnf', "[mysqld]\nbind-address = 127.0.0.1\ninnodb_buffer_pool_size = 64M\n"),
            // policy-rc.d shim — `exit 101` is the documented contract
            // for "service must NOT be started during this dpkg run".
            // Debian/Ubuntu packages call invoke-rc.d which honours it;
            // mysql-server's postinst respects this and skips the
            // start, so we control daemon launch ourselves.
            // printf, not echo: bash's default echo doesn't interpret
            // \n, so `echo "#!/bin/sh\nexit 101"` writes a literal
            // backslash-n and the shim ends up as a single broken line.
            'printf \'%s\n%s\n\' \'#!/bin/sh\' \'exit 101\' > /usr/sbin/policy-rc.d',
            'chmod +x /usr/sbin/policy-rc.d',
            'echo "[dply] policy-rc.d shim installed — mysql.service will NOT auto-start during package install."',
            // Install the package. mysql_install_db still runs via
            // postinst (good — that's what writes /var/lib/mysql),
            // but no systemctl start happens.
            ...$this->ensurePackagesInstalled(
                ['mysql-server'],
                '[dply] mysql-server already installed; skipping package install.'
            ),
            // Drop the shim so subsequent service operations work.
            'rm -f /usr/sbin/policy-rc.d',
            'echo "[dply] policy-rc.d shim removed."',
            // Now that the mysql user/group exist, fix the runtime dir.
            'chown mysql:mysql /var/run/mysqld 2>/dev/null || true',
            // Steady-state config — after init has succeeded, we can
            // give mysql a more useful buffer pool. The 99- prefix
            // ensures it overrides the 00-dply-init.cnf low-memory
            // bootstrap value.
            $this->writeFileWithRollback('/etc/mysql/mysql.conf.d/99-dply.cnf', "[mysqld]\nbind-address = 127.0.0.1\nmax_connections = 200\ninnodb_buffer_pool_size = 256M\n"),
            // Start the daemon ourselves. If this fails, we fail loud
            // with the actual journal output rather than burying the
            // failure in a dpkg status transition.
            'systemctl daemon-reload >/dev/null 2>&1 || true',
            'systemctl enable mysql >/dev/null 2>&1 || true',
            'if ! systemctl start mysql; then '
                .'echo "[dply] MySQL service failed to start on first attempt — clearing systemd failure state and retrying." >&2; '
                .'systemctl reset-failed mysql >/dev/null 2>&1 || true; '
                .'sleep 3; '
                .'systemctl start mysql || { '
                    .'echo "[dply] ERROR: MySQL still not running after reset-failed retry." >&2; '
                    .'echo "[dply] === journalctl -u mysql (last 60 lines) ===" >&2; '
                    .'journalctl -u mysql --no-pager -n 60 >&2 || true; '
                    .'echo "[dply] === /var/log/mysql/error.log (last 50 lines) ===" >&2; '
                    .'tail -n 50 /var/log/mysql/error.log >&2 2>/dev/null || echo "(no error.log)" >&2; '
                    .'echo "[dply] === free -h ===" >&2; '
                    .'free -h >&2 || true; '
                    .'exit 1; '
                .'}; '
            .'fi',
            // Wait up to 30s for the daemon to actually accept
            // connections — systemctl returns when the unit is active,
            // but mysqld can still be running internal init.
            'echo "[dply] waiting for mysqld socket..."',
            'for i in 1 2 3 4 5 6 7 8 9 10; do '
                .'if mysqladmin --protocol=socket -uroot ping >/dev/null 2>&1; then '
                    .'echo "[dply] MySQL is accepting connections."; break; '
                .'fi; '
                .'sleep 3; '
            .'done',
            'echo "[dply] MySQL variants (5.7/8.0/8.4) use distro mysql-server package where applicable; pin versions in follow-up automation if required."',
            // Reconciliation marker: normal-path mysql install, snapshot
            // records the wizard-requested engine string verbatim.
            'export DPLY_INSTALLED_DATABASE='.escapeshellarg($wizardDatabase),
        ];

        // Wrap both branches in a single conditional. The bash script's
        // DPLY_LOW_MEM env var is set in bootstrap based on probed RAM.
        return [
            implode("\n", array_merge(
                ['if [ "${DPLY_LOW_MEM:-0}" = "1" ]; then'],
                array_map(static fn (string $line): string => '  '.$line, $sqliteFallback),
                ['else'],
                array_map(static fn (string $line): string => '  '.$line, $mysqlInstall),
                ['fi'],
            )),
        ];
    }

    /**
     * @return list<string>
     */
    private function installPostgresql(string $database): array
    {
        $ver = match ($database) {
            'postgres14' => '14',
            'postgres15' => '15',
            'postgres16' => '16',
            'postgres17' => '17',
            'postgres18' => '18',
            default => '16',
        };

        // Same low-memory escape hatch as MySQL — Postgres needs ~250MB+
        // working set for `initdb` plus another ~150MB for the daemon
        // baseline, which is enough to fail on ≤512MB droplets.
        $sqliteFallback = [
            'echo "[dply] LOW-MEMORY MODE: skipping PostgreSQL '.$ver.' install — droplet has only ${DPLY_TOTAL_MEM_MB}MB RAM."',
            'echo "[dply] Installing SQLite as a substitute. Re-provision on a 2GB+ droplet to switch to PostgreSQL."',
            'apt-get install -y --no-install-recommends sqlite3 libsqlite3-0',
            'echo "[dply] SQLite installed in low-memory mode."',
            'export DPLY_INSTALLED_DATABASE="sqlite3"',
        ];

        $postgresInstall = [
            'install -d /usr/share/postgresql-common/pgdg',
            'curl -fsSL -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc https://www.postgresql.org/media/keys/ACCC4CF8.asc',
            'chmod 644 /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc',
            '. /etc/os-release && echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt ${VERSION_CODENAME}-pgdg main" > /etc/apt/sources.list.d/pgdg.list',
            'apt-get update -y',
            ...$this->ensurePackagesInstalled(
                ['postgresql-'.$ver],
                '[dply] postgresql-'.$ver.' already installed; skipping package install.'
            ),
            $this->writeFileWithRollback('/etc/postgresql/'.$ver.'/main/conf.d/99-dply.conf', "listen_addresses = '127.0.0.1'\nshared_buffers = '256MB'\nmax_connections = 200\n"),
            'systemctl enable --now postgresql',
            'systemctl restart postgresql || true',
            'export DPLY_INSTALLED_DATABASE='.escapeshellarg($database),
        ];

        return [
            $this->stepMarker('Installing PostgreSQL'),
            implode("\n", array_merge(
                ['if [ "${DPLY_LOW_MEM:-0}" = "1" ]; then'],
                array_map(static fn (string $line): string => '  '.$line, $sqliteFallback),
                ['else'],
                array_map(static fn (string $line): string => '  '.$line, $postgresInstall),
                ['fi'],
            )),
        ];
    }

    private function phpStem(string $phpVersionId): string
    {
        if (! preg_match('/^(\d+)\.(\d+)$/', $phpVersionId, $m)) {
            return 'php8.3';
        }

        return 'php'.$m[1].'.'.$m[2];
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
        return $this->withStep('Preparing server filesystem', [
            'install -d -m 0755 '.implode(' ', array_map('escapeshellarg', [
                $layout['web_root'],
                $layout['logs'],
            ])),
            // Render a dply-branded landing page so port 80 returns
            // something honest until the operator creates a site.
            'cat > '.escapeshellarg($layout['web_root'].'/index.html').' <<\'EOF\'
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>dply server ready</title><style>body{font-family:system-ui,sans-serif;max-width:36rem;margin:6rem auto;padding:0 1.5rem;color:#171a0e}h1{font-size:1.5rem;margin:0 0 .5rem}p{color:#5a6354}code{background:#f6f4ee;padding:.15rem .35rem;border-radius:.25rem}</style></head><body><h1>dply server ready</h1><p>This server is provisioned but has no sites yet. Create one from your dply dashboard or via <code>dply:site:create</code>.</p></body></html>
EOF',
            // Owned by dply:dply since the docroot now lives under
            // /home/dply/. Nginx (running as www-data) needs read +
            // execute on the path; the dply user's home dir is
            // chmod 0755 + dply group is in www-data's reachable
            // group set, so traversal works. dply owns the bytes,
            // nginx serves them.
            'chown -R dply:dply '.escapeshellarg($layout['web_root']).' || true',
            'chmod 755 '.escapeshellarg($layout['web_root']),
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

        if ($role === 'load_balancer') {
            $checks['haproxy'] = 'haproxy -c -f /etc/haproxy/haproxy.cfg';
        }

        if ($role === 'docker') {
            $checks['docker'] = 'docker --version';
            $checks['docker-daemon'] = 'systemctl is-active docker';
        }

        return $checks;
    }

    /**
     * @return list<string>
     */
    private function roleHardening(string $role): array
    {
        return match ($role) {
            'database' => $this->withStep('Applying role hardening', [
                'install -d -m 0755 /etc/sysctl.d',
                'printf "vm.swappiness=10\nnet.ipv4.tcp_syncookies=1\n" > /etc/sysctl.d/99-dply-database.conf',
                'sysctl --system >/dev/null 2>&1 || true',
            ]),
            'load_balancer' => $this->withStep('Applying role hardening', [
                'printf "net.core.somaxconn=4096\n" > /etc/sysctl.d/99-dply-haproxy.conf',
                'sysctl --system >/dev/null 2>&1 || true',
            ]),
            default => $this->withStep('Applying role hardening', [
                'printf "fs.inotify.max_user_watches=524288\n" > /etc/sysctl.d/99-dply-app.conf',
                'sysctl --system >/dev/null 2>&1 || true',
            ]),
        };
    }

    private function writeFileWithRollback(string $path, string $content): string
    {
        $encodedPath = base64_encode($path);
        $encodedContent = base64_encode($content);

        return 'dply_write_file '.escapeshellarg($encodedPath).' '.escapeshellarg($encodedContent);
    }

    private function isAllowed(string $section, string $id): bool
    {
        $rows = config('server_provision_options.'.$section, []);

        return collect(is_array($rows) ? $rows : [])->pluck('id')->contains($id);
    }

    private function coalesceId(string $section, mixed $value, string $fallback): string
    {
        if (is_string($value) && $this->isAllowed($section, $value)) {
            return $value;
        }

        return $this->isAllowed($section, $fallback) ? $fallback : (string) $value;
    }

    private function stepMarker(string $label): string
    {
        return 'echo '.escapeshellarg(self::STEP_PREFIX.$label);
    }

    /**
     * Emit a tab-delimited end marker:
     *   `[dply-step-end] <label>\t<seconds>` (normal)
     *   `[dply-step-end] <label>\t0\tresumed` (resume-skip)
     *
     * Tab-delimited so {@see App\Support\Servers\ProvisionStepDurations::parse()}
     * can split fields without ambiguity (labels can include spaces).
     *
     * `$secondsExpression` is interpolated raw into the printf arg so the
     * caller can pass a bash expression like `$((NOW - START))`. For
     * resumed rows the caller passes the literal `0`.
     */
    private function stepEndMarker(string $label, string $secondsExpression, bool $resumed = false): string
    {
        $resumedSuffix = $resumed ? '\tresumed' : '';
        // Both label and seconds are passed as printf args (not embedded
        // in the format string) so any character a label could contain
        // — quotes, $, backticks — stays inert. The format string itself
        // is single-quoted bash so printf sees `\t` / `\n` as escapes.
        $format = self::STEP_END_PREFIX.'%s\t%s'.$resumedSuffix.'\n';

        return sprintf(
            "printf '%s' %s %s",
            $format,
            escapeshellarg($label),
            $secondsExpression,
        );
    }

    /**
     * Wrap a step's commands in a resume-on-failure guard.
     *
     * On success, the step writes /var/lib/dply/steps/<key>.done.
     * On the next run (manual "Resume install" or auto-retry), the
     * presence of that file short-circuits the entire step body —
     * the journey output reads "[dply-step] X (resumed: already
     * done)" and execution moves immediately to the next step.
     *
     * The marker key is a hash of label + step body, NOT just the
     * label. If the operator changes the step's bash (adds a flag,
     * tweaks an apt package list), the hash changes, the old marker
     * no longer matches, and the step re-executes. Without this,
     * iterating on the script while resuming would silently skip
     * the new code — a real foot-gun.
     *
     * Markers live under /var/lib/dply/steps/ which is created on
     * demand. Force-reinstall mode wipes the directory at bootstrap
     * (see bootstrap()).
     *
     * @param  list<string>  $commands
     * @return list<string>
     */
    private function withStep(string $label, array $commands): array
    {
        $body = implode("\n", $commands);
        $key = substr(md5($label.'|'.$body), 0, 16);
        $marker = '/var/lib/dply/steps/'.$key.'.done';
        $skipMarker = $this->stepMarker($label.' (resumed: already done)');

        // Each step is wrapped with a wall-clock duration capture that
        // emits a `[dply-step-end]` marker on exit. ProvisionStepDurations
        // parses those markers when the task reaches a terminal state and
        // inserts one row per step into server_provision_step_runs, which
        // ProvisionStepEtaService averages to power the "Avg X min from N
        // runs" ETA in the journey UI.
        //
        // _DPLY_STEP_STARTED is per-step so nested withStep calls (none
        // today, but defensively) don't clobber each other.
        $endMarker = $this->stepEndMarker($label, '"$(( $(date +%s) - _DPLY_STEP_STARTED ))"');
        $resumedEndMarker = $this->stepEndMarker($label, '0', resumed: true);

        return [
            // The whole step is one composite shell command so the
            // marker write only happens if every inner command
            // succeeded (set -e on the outer script propagates). The
            // `else` branch logs the skip so the journey UI still
            // sees a step marker line and can paint it as completed.
            implode("\n", [
                'if [ ! -f '.escapeshellarg($marker).' ]; then',
                '  '.$this->stepMarker($label),
                '  _DPLY_STEP_STARTED=$(date +%s)',
                $body,
                '  install -d -m 0755 /var/lib/dply/steps',
                '  touch '.escapeshellarg($marker),
                '  '.$endMarker,
                'else',
                '  '.$skipMarker,
                '  '.$resumedEndMarker,
                'fi',
            ]),
        ];
    }

    /**
     * @param  list<string>  $packages
     * @return list<string>
     */
    private function ensurePackagesInstalled(array $packages, string $alreadyInstalledMessage): array
    {
        $packages = array_values(array_filter($packages, fn (string $package): bool => trim($package) !== ''));
        if ($packages === []) {
            return [];
        }

        $checks = array_map(
            fn (string $package): string => 'dpkg -s '.escapeshellarg($package).' >/dev/null 2>&1',
            $packages,
        );

        if ($this->forceReinstall()) {
            return [
                'apt-get install -y --no-install-recommends '.implode(' ', $packages),
            ];
        }

        return [
            'if '.implode(' && ', $checks).'; then echo '.escapeshellarg($alreadyInstalledMessage).'; else apt-get install -y --no-install-recommends '.implode(' ', $packages).'; fi',
        ];
    }

    /**
     * @return list<string>
     */
    private function ensureOndrejPhpRepository(): array
    {
        // We need the ondrej/php builds (Ubuntu's stock noble repo only
        // ships php8.3, no 8.4). Two upstreams publish the SAME builds:
        //
        //   1. packages.sury.org/php       — Ondřej Surý's primary repo
        //   2. ppa.launchpadcontent.net    — secondary mirror via Launchpad
        //
        // The package version strings (e.g. `8.4.20-1+ubuntu24.04.1+deb.sury.org+1`)
        // make the relationship explicit: deb.sury.org is the source.
        //
        // Launchpad has a documented history of regional reachability
        // failures from VPS hosts — DigitalOcean droplets in particular
        // hit "Could not connect to ppa.launchpadcontent.net:443
        // (185.125.190.80), connection timed out" frequently enough that
        // it's no longer transient. Sury's host is far more reliable.
        //
        // Strategy: probe sury.org first (5s reachability check). If it
        // responds, use it. If not, fall back to Launchpad. Then verify
        // success by checking that an InRelease file actually fetched
        // into /var/lib/apt/lists/ — `apt-cache policy` only proves the
        // source is *configured*, not that any data was fetched, so the
        // old grep-based check happily declared success on `Err:6`.
        $aptUpdateWithRetry = implode("\n", [
            'success=0',
            'lock_retries=0',
            'for attempt in 1 2 3 4 5 6; do',
            '  dply_wait_for_apt_locks || exit 1',
            '  echo "[dply] apt-get update attempt $attempt/6 (refreshing ondrej/php sources)..."',
            '  update_log=$(timeout 300s apt-get update -y -o Acquire::Retries=3 -o Acquire::http::Timeout=30 2>&1 || true)',
            '  echo "$update_log"',
            '  if echo "$update_log" | grep -qE "Could not get lock|is held by process"; then',
            '    if [ "$lock_retries" -lt 6 ]; then',
            '      lock_retries=$((lock_retries + 1))',
            '      echo "[dply] another apt-get acquired the lock during our update — re-waiting (lock-retry $lock_retries/6)."',
            '      sleep 15',
            '      attempt=$((attempt - 1))',
            '      continue',
            '    fi',
            '    echo "[dply] WARNING: lock contention persisted across 6 attempts; treating this as a real failure." >&2',
            '  fi',
            // Real success check: did an InRelease file actually land?
            // ls returns empty if no file matches; -A1 keeps it on one
            // line. Match either upstream so this works regardless of
            // which source we activated.
            '  if ls /var/lib/apt/lists/ 2>/dev/null | grep -qE "(packages\\.sury\\.org|ppa\\.launchpadcontent\\.net).*_InRelease$"; then',
            '    echo "[dply] ondrej/php InRelease successfully fetched."',
            '    success=1; break',
            '  fi',
            '  echo "[dply] ondrej/php InRelease not yet present in /var/lib/apt/lists (attempt $attempt/6) — sleeping 30s before retry."',
            '  sleep 30',
            'done',
            'if [ "$success" -ne 1 ]; then',
            '  echo "[dply] ERROR: ondrej/php InRelease never fetched after 6 retries." >&2',
            '  echo "[dply] Diagnostic checklist (in priority order):" >&2',
            '  echo "[dply]   1. Is another apt running? Try: ps auxf | grep -E \'apt|unattended\' on the host" >&2',
            '  echo "[dply]   2. Is the keyring present? Check: ls -la /etc/apt/keyrings/sury-php.gpg /etc/apt/keyrings/ondrej-php.gpg" >&2',
            '  echo "[dply]   3. Can the host reach either upstream?" >&2',
            '  echo "[dply]      curl -I https://packages.sury.org/php/" >&2',
            '  echo "[dply]      curl -I https://ppa.launchpadcontent.net/ondrej/php/ubuntu/" >&2',
            '  exit 1',
            'fi',
        ]);

        // Reachability probe: prefer sury.org, fall back to Launchpad.
        // -m 5 caps each probe at 5s so we don't add latency on the
        // happy path. The chosen source is written to a flag file so
        // the keyring + sources.list step picks it up.
        $selectUpstream = implode("\n", [
            'echo "[dply] probing ondrej/php upstreams..."',
            'if curl -fsI -m 5 https://packages.sury.org/php/ >/dev/null 2>&1; then',
            '  echo "[dply] using packages.sury.org (primary upstream)"',
            '  echo sury > /tmp/dply-ondrej-source',
            'elif curl -fsI -m 5 https://ppa.launchpadcontent.net/ondrej/php/ubuntu/ >/dev/null 2>&1; then',
            '  echo "[dply] sury.org unreachable — falling back to ppa.launchpadcontent.net"',
            '  echo launchpad > /tmp/dply-ondrej-source',
            'else',
            '  echo "[dply] ERROR: neither packages.sury.org nor ppa.launchpadcontent.net is reachable from this host." >&2',
            '  echo "[dply] Run from the host to diagnose:" >&2',
            '  echo "[dply]   curl -v https://packages.sury.org/php/" >&2',
            '  echo "[dply]   curl -v https://ppa.launchpadcontent.net/ondrej/php/ubuntu/" >&2',
            '  exit 1',
            'fi',
        ]);

        // Sury and Launchpad need different keyring files (different
        // signing keys) and different sources.list entries. The case
        // statement reads the flag from the probe step.
        $installRepo = implode("\n", [
            'install -d -m 0755 /etc/apt/keyrings',
            'case "$(cat /tmp/dply-ondrej-source)" in',
            '  sury)',
            // Sury's published key URL.
            '    curl -fsSL --retry 3 --retry-delay 2 --max-time 60 https://packages.sury.org/php/apt.gpg \\',
            '      | gpg --dearmor --yes -o /etc/apt/keyrings/sury-php.gpg',
            '    chmod 0644 /etc/apt/keyrings/sury-php.gpg',
            '    echo "deb [signed-by=/etc/apt/keyrings/sury-php.gpg] https://packages.sury.org/php/ $(lsb_release -cs) main" \\',
            '      > /etc/apt/sources.list.d/sury-php.list',
            '    rm -f /etc/apt/sources.list.d/ondrej-php.list',
            '    ;;',
            '  launchpad)',
            // Launchpad-published key for the ondrej/php signing keypair.
            '    curl -fsSL --retry 3 --retry-delay 2 --max-time 60 \\',
            '      "https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x14aa40ec0831756756d7f66c4f4ea0aae5267a6c" \\',
            '      | gpg --dearmor --yes -o /etc/apt/keyrings/ondrej-php.gpg',
            '    chmod 0644 /etc/apt/keyrings/ondrej-php.gpg',
            '    echo "deb [signed-by=/etc/apt/keyrings/ondrej-php.gpg] https://ppa.launchpadcontent.net/ondrej/php/ubuntu $(lsb_release -cs) main" \\',
            '      > /etc/apt/sources.list.d/ondrej-php.list',
            '    rm -f /etc/apt/sources.list.d/sury-php.list',
            '    ;;',
            'esac',
        ]);

        $setupRepo = $selectUpstream."\n".$installRepo."\n".$aptUpdateWithRetry;

        if ($this->forceReinstall()) {
            return [$setupRepo];
        }

        // Skip the whole dance if either source is already wired up
        // AND its InRelease file is present (i.e. last apt-get update
        // actually succeeded). If the source file exists but no
        // InRelease, we re-run setup so the next attempt has a chance
        // to fetch from the alternate upstream.
        $alreadyInstalled = 'grep -RqsE "packages\\.sury\\.org/php|ppa\\.launchpadcontent\\.net/ondrej/php" /etc/apt/sources.list /etc/apt/sources.list.d '
            .'&& ls /var/lib/apt/lists/ 2>/dev/null | grep -qE "(packages\\.sury\\.org|ppa\\.launchpadcontent\\.net).*_InRelease$"';

        return [
            'if '.$alreadyInstalled.'; then '
                .'echo "[dply] ondrej/php repository already installed and indexed; skipping repository setup."; '
                .'else '.$setupRepo.'; '
                .'fi',
        ];
    }

    private function forceReinstall(): bool
    {
        return (bool) config('server_provision.force_reinstall', false);
    }
}
