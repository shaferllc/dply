<?php

declare(strict_types=1);

namespace App\Services\Servers\Concerns;



/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait BuildsProvisionWebserverPhp
{


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
            $lines[] = 'dply_apt_update';
            $lines = array_merge($lines, $this->ensurePackagesInstalled(
                ['openlitespeed'],
                '[dply] openlitespeed already installed; skipping package install.'
            ));
            $lines[] = 'ufw allow 80/tcp';
            $lines[] = 'ufw allow 443/tcp';
            $lines[] = '/usr/local/lsws/bin/lswsctrl start || true';
        } elseif ($web === 'caddy') {
            $lines[] = $this->stepMarker('Installing webserver');
            $lines[] = 'install -d /usr/share/keyrings';
            $lines[] = 'curl -fsSL https://dl.cloudsmith.io/public/caddy/stable/gpg.key | gpg --batch --yes --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg';
            $lines[] = 'curl -fsSL https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt | tee /etc/apt/sources.list.d/caddy-stable.list';
            $lines[] = 'dply_apt_update';
            $lines = array_merge($lines, $this->ensurePackagesInstalled(
                ['caddy'],
                '[dply] caddy already installed; skipping package install.'
            ));
            $lines[] = 'ufw allow 80/tcp';
            $lines[] = 'ufw allow 443/tcp';
            $lines[] = 'systemctl enable --now caddy';
        } elseif ($web === 'traefik') {
            $lines[] = $this->stepMarker('Installing webserver');
            $lines = array_merge($lines, $this->ensurePackagesInstalled(
                ['traefik', 'caddy'],
                '[dply] traefik + caddy already installed; skipping package install.'
            ));
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
        // Optionally defer certbot off the provision critical path — the cert
        // issuance builder ensures certbot is present on first use, so this is
        // correctness-preserving. Off by default (installs here as before).
        if ((bool) config('server_provision.defer_certbot', false)) {
            return [];
        }

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

        // Only set up the ondrej/sury PPA when the distro repo can't already
        // provide the requested PHP. Ubuntu 24.04 ships php8.3 natively, so for
        // the default version this skips the upstream probe + keyring + an extra
        // apt-get update (~90s) entirely. Gated at runtime on `apt-cache show`
        // (after the base update) rather than a hardcoded codename→version map,
        // so it stays correct as newer Ubuntu releases ship newer PHP. When the
        // requested version isn't in the distro repo (e.g. 8.4 on noble), the
        // PPA is added as before.
        $cliPkg = $stem.'-cli';
        $lines = [
            $this->stepMarker('Installing PHP '.$php),
            implode("\n", [
                'if apt-cache show '.escapeshellarg($cliPkg).' >/dev/null 2>&1; then',
                '  echo "[dply] '.$cliPkg.' is available from the distro repository — skipping ondrej/sury PPA setup."',
                'else',
                '  echo "[dply] '.$cliPkg.' not in the distro repository — adding ondrej/sury PPA."',
                implode("\n", $this->ensureOndrejPhpRepository()),
                'fi',
            ]),
        ];

        // Core PHP packages — the runtime, the FPM unit, the shared module
        // bundle, the must-have string/markup/HTTP extensions, the selected DB
        // driver, and SQLite for the test suite. These all exist in both
        // Ubuntu's stock repo and ondrej/sury, so a missing one here is a
        // genuine failure that SHOULD abort the provision (strict install).
        $requiredPkgs = [
            $stem.'-cli',
            $stem.'-fpm',
            $stem.'-common',
            $stem.'-mbstring',
            $stem.'-xml',
            $stem.'-curl',
        ];

        if (str_starts_with($database, 'postgres')) {
            $requiredPkgs[] = $stem.'-pgsql';
        } else {
            // Default / MySQL / MariaDB / unrecognised → MySQL driver.
            $requiredPkgs[] = $stem.'-mysql';
        }

        // SQLite is always installed regardless of the chosen primary database:
        // virtually every Laravel app uses SQLite for the test suite (RefreshDatabase
        // with :memory: or a tmp file), even when production runs MySQL or Postgres.
        // The setup-script presets already bundle it unconditionally; this aligns
        // the apt-provisioned path so `php artisan test` works out of the box.
        $requiredPkgs[] = $stem.'-sqlite3';

        // Optional extensions — these improve compatibility/performance but a
        // Laravel app boots fine without any of them, and crucially NOT every
        // one is packaged in every repo. Ubuntu noble ships php8.3 but builds
        // sodium into the core (there is no separate php8.3-sodium package), and
        // ondrej/sury can be briefly unreachable from a fresh droplet. Installed
        // best-effort (any package apt can't see is skipped with a log line) so
        // a single unavailable extension can't abort the whole provision — which
        // is exactly what wedged servers on `E: Unable to locate package
        // php8.3-sodium`.
        $optionalPkgs = [
            // phpredis client extension. Provisioning installs the Redis *daemon*
            // (see roleCacheHost/roleRedis); the PHP client default is phpredis,
            // so without it a Laravel app hits `Class "Redis" not found`.
            $stem.'-redis',
            // GD image library — spatie/media-library, intervention/image, QR/avatar libs.
            $stem.'-gd',
            // Sodium cryptography — Laravel prefers ext-sodium (falls back to openssl).
            $stem.'-sodium',
            // GNU Multiple Precision — ramsey/uuid, moneyphp/money, league/uri, web-auth.
            $stem.'-gmp',
            // APCu in-memory user cache — the `apcu` cache driver.
            $stem.'-apcu',
            // igbinary serializer — compact payloads for phpredis cache/session/queue.
            $stem.'-igbinary',
            // Archive handling (Composer), i18n formatting, arbitrary-precision math.
            $stem.'-zip',
            $stem.'-intl',
            $stem.'-bcmath',
        ];

        // OPcache is a standalone phpX.Y-opcache package up to 8.4; from 8.5 it
        // ships bundled with the core build (no separate package), so on 8.5+
        // the best-effort filter below simply skips it.
        if (version_compare($php, '8.5', '<')) {
            $optionalPkgs[] = $stem.'-opcache';
        }

        // Core + optional extensions in ONE apt transaction (was two): core is
        // strict, optional is filtered to what's available, so a single dpkg run
        // installs everything that exists without a missing optional aborting it.
        $lines = array_merge($lines, $this->ensureMixedPackagesInstalled(
            $requiredPkgs,
            $optionalPkgs,
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
                ...$this->ensurePackagesInstalled(
                    ['libapache2-mod-'.$stem],
                    '[dply] apache PHP module already installed; skipping package install.'
                ),
                'a2enmod '.$stem,
                'systemctl reload apache2',
            ];
        }

        if ($web === 'openlitespeed') {
            // OpenLiteSpeed runs its own lsphp build, separate from the phpX.Y-fpm
            // packages above — so it needs lsphpNN-redis explicitly, or OLS-served
            // Laravel apps still hit `Class "Redis" not found` even though the
            // phpX.Y CLI has the extension.
            $lsphp = 'lsphp'.str_replace('.', '', $php);

            return [
                'apt-get install -y --no-install-recommends '.$lsphp.' '.$lsphp.'-mysql '.$lsphp.'-pgsql '.$lsphp.'-redis || true',
                '/usr/local/lsws/bin/lswsctrl restart || true',
            ];
        }

        return [];
    }

    private function phpStem(string $phpVersionId): string
    {
        if (! preg_match('/^(\d+)\.(\d+)$/', $phpVersionId, $m)) {
            return 'php8.3';
        }

        return 'php'.$m[1].'.'.$m[2];
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
            '  if echo "$update_log" | grep -qE "Could not get lock|Unable to acquire the dpkg frontend lock|is held by process"; then',
            '    if [ "$lock_retries" -lt 6 ]; then',
            '      lock_retries=$((lock_retries + 1))',
            '      echo "[dply] another apt-get acquired the lock during our update — re-waiting (lock-retry $lock_retries/6)."',
            '      sleep 15',
            '      attempt=$((attempt - 1))',
            '      continue',
            '    fi',
            '    echo "[dply] ERROR: apt lock contention persisted across 6 retries during ondrej/php update." >&2',
            '    exit 100',
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
}
