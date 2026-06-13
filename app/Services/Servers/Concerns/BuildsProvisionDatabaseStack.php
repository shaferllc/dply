<?php

declare(strict_types=1);

namespace App\Services\Servers\Concerns;



/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait BuildsProvisionDatabaseStack
{


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
            // Wait for the daemon to actually accept connections — systemctl
            // returns when the unit is active, but mysqld can still be running
            // internal init. Poll at 1s (was 3s) so we proceed the moment it's
            // ready instead of overshooting by up to ~3s; same ~60s ceiling.
            'echo "[dply] waiting for mysqld socket..."',
            'for i in $(seq 1 60); do '
                .'if mysqladmin --protocol=socket -uroot ping >/dev/null 2>&1; then '
                    .'echo "[dply] MySQL is accepting connections (after ${i}s)."; break; '
                .'fi; '
                .'sleep 1; '
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
            'dply_apt_update',
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
}
