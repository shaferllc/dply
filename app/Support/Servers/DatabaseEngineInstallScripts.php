<?php

declare(strict_types=1);

namespace App\Support\Servers;

/**
 * Database-engine-side mirror of {@see CacheServiceInstallScripts}. Holds the install / uninstall
 * / version-probe / config-path bash for the engines the workspace can manage at runtime.
 */
final class DatabaseEngineInstallScripts
{
    /**
     * @return list<string>
     */
    public static function supportedEngines(): array
    {
        return ['mysql', 'mariadb', 'postgres', 'mongodb', 'clickhouse'];
    }

    public static function installScript(string $engine): string
    {
        return match ($engine) {
            'mysql' => <<<'BASH'
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y mysql-server
systemctl enable --now mysql
mysql --version
BASH,
            'mariadb' => <<<'BASH'
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y mariadb-server
systemctl enable --now mariadb || systemctl enable --now mysql
(mariadb --version || mysql --version) 2>/dev/null
BASH,
            'postgres' => <<<'BASH'
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y postgresql
systemctl enable --now postgresql
psql --version
BASH,
            'mongodb' => <<<'BASH'
export DEBIAN_FRONTEND=noninteractive
set -e
apt-get update -y
apt-get install -y gnupg curl ca-certificates
if [ -f /etc/os-release ]; then
  . /etc/os-release
  if [ "${ID}" = "ubuntu" ] && [ -n "${VERSION_CODENAME}" ]; then
    curl -fsSL https://www.mongodb.org/static/pgp/server-7.0.asc | gpg -o /usr/share/keyrings/mongodb-server-7.0.gpg --dearmor
    echo "deb [ arch=amd64,arm64 signed-by=/usr/share/keyrings/mongodb-server-7.0.gpg ] https://repo.mongodb.org/apt/ubuntu ${VERSION_CODENAME}/mongodb-org/7.0 multiverse" > /etc/apt/sources.list.d/mongodb-org-7.0.list
    apt-get update -y
    apt-get install -y mongodb-org || true
  fi
fi
apt-get install -y mongodb-org 2>/dev/null || apt-get install -y mongodb 2>/dev/null || true
systemctl enable --now mongod 2>/dev/null || true
(mongosh --version 2>/dev/null || mongo --version 2>/dev/null) | head -n1
BASH,
            'clickhouse' => <<<'BASH'
export DEBIAN_FRONTEND=noninteractive
set -e
apt-get update -y
apt-get install -y apt-transport-https ca-certificates curl gnupg
curl -fsSL 'https://packages.clickhouse.com/rpm/lts/repodata/repomd.xml.key' | gpg --dearmor -o /usr/share/keyrings/clickhouse-keyring.gpg
echo "deb [signed-by=/usr/share/keyrings/clickhouse-keyring.gpg] https://packages.clickhouse.com/deb stable main" > /etc/apt/sources.list.d/clickhouse.list
apt-get update -y
printf '%s\n%s\n' '#!/bin/sh' 'exit 101' > /usr/sbin/policy-rc.d
chmod +x /usr/sbin/policy-rc.d
apt-get install -y clickhouse-server clickhouse-client
rm -f /usr/sbin/policy-rc.d
install -d -o clickhouse -g clickhouse -m 0750 /var/lib/clickhouse /var/log/clickhouse-server 2>/dev/null || true
chown -R clickhouse:clickhouse /etc/clickhouse-server /var/lib/clickhouse /var/log/clickhouse-server 2>/dev/null || true
mkdir -p /etc/systemd/system/clickhouse-server.service.d
printf '[Service]\nTimeoutStartSec=300\n' > /etc/systemd/system/clickhouse-server.service.d/dply.conf
systemctl daemon-reload
systemctl enable clickhouse-server
# Memory-aware tuning. ClickHouse's defaults (cap = 0.9 * RAM, multi-GB caches)
# can OOM a small or shared app box. When total RAM is modest, cap CH so it
# coexists with whatever else runs on the box; bigger/dedicated boxes keep the
# stock defaults so a real log store isn't hobbled.
RAM_MB=$(awk '/MemTotal/{printf "%d", $2/1024}' /proc/meminfo 2>/dev/null || echo 0)
if [ "$RAM_MB" -gt 0 ] && [ "$RAM_MB" -lt 4096 ]; then
  CH_MAX_BYTES=$(( RAM_MB * 1024 * 1024 / 2 ))
  CH_SPILL_BYTES=$(( CH_MAX_BYTES / 2 ))
  mkdir -p /etc/clickhouse-server/config.d
  cat > /etc/clickhouse-server/config.d/99-dply-low-memory.xml <<EOF
<clickhouse>
    <max_server_memory_usage>${CH_MAX_BYTES}</max_server_memory_usage>
    <mark_cache_size>268435456</mark_cache_size>
    <uncompressed_cache_size>0</uncompressed_cache_size>
    <max_concurrent_queries>16</max_concurrent_queries>
    <background_pool_size>4</background_pool_size>
    <background_schedule_pool_size>4</background_schedule_pool_size>
    <profiles>
        <default>
            <max_memory_usage>${CH_MAX_BYTES}</max_memory_usage>
            <max_bytes_before_external_group_by>${CH_SPILL_BYTES}</max_bytes_before_external_group_by>
            <max_bytes_before_external_sort>${CH_SPILL_BYTES}</max_bytes_before_external_sort>
        </default>
    </profiles>
</clickhouse>
EOF
  chown clickhouse:clickhouse /etc/clickhouse-server/config.d/99-dply-low-memory.xml 2>/dev/null || true
fi
if ! systemctl start clickhouse-server; then
  systemctl reset-failed clickhouse-server >/dev/null 2>&1 || true
  sleep 3
  systemctl start clickhouse-server || {
    echo "[dply] ERROR: clickhouse-server failed to start." >&2
    journalctl -u clickhouse-server --no-pager -n 60 >&2 || true
    tail -n 50 /var/log/clickhouse-server/clickhouse-server.err.log >&2 2>/dev/null || echo "(no err.log)" >&2
    exit 1
  }
fi
for i in 1 2 3 4 5 6; do
  if systemctl is-active --quiet clickhouse-server; then
    break
  fi
  sleep 5
done
systemctl is-active --quiet clickhouse-server
clickhouse-client --version 2>/dev/null | head -n1
BASH,
            default => throw new \InvalidArgumentException("Unsupported database engine: {$engine}"),
        };
    }

    public static function uninstallScript(string $engine): string
    {
        return match ($engine) {
            'mysql' => <<<'BASH'
export DEBIAN_FRONTEND=noninteractive
systemctl disable --now mysql || true
apt-get purge -y mysql-server mysql-client mysql-common 'mysql-server-*' 'mysql-client-*' || true
apt-get autoremove -y
BASH,
            'mariadb' => <<<'BASH'
export DEBIAN_FRONTEND=noninteractive
systemctl disable --now mariadb mysql || true
apt-get purge -y mariadb-server mariadb-client 'mariadb-server-*' 'mariadb-client-*' || true
apt-get autoremove -y
BASH,
            'postgres' => <<<'BASH'
export DEBIAN_FRONTEND=noninteractive
systemctl disable --now postgresql || true
apt-get purge -y postgresql 'postgresql-*' postgresql-client 'postgresql-client-*' postgresql-contrib || true
apt-get autoremove -y
BASH,
            'mongodb' => <<<'BASH'
export DEBIAN_FRONTEND=noninteractive
systemctl disable --now mongod || true
apt-get purge -y mongodb-org mongodb-org-database mongodb-org-server mongodb-org-mongos mongodb-org-tools mongodb || true
rm -f /etc/apt/sources.list.d/mongodb-org-7.0.list 2>/dev/null || true
apt-get autoremove -y
BASH,
            'clickhouse' => <<<'BASH'
export DEBIAN_FRONTEND=noninteractive
systemctl disable --now clickhouse-server || true
apt-get purge -y clickhouse-server clickhouse-client clickhouse-common-static || true
rm -f /etc/apt/sources.list.d/clickhouse.list 2>/dev/null || true
apt-get autoremove -y
BASH,
            default => throw new \InvalidArgumentException("Unsupported database engine: {$engine}"),
        };
    }

    public static function versionProbeScript(string $engine): string
    {
        return match ($engine) {
            'mysql' => 'mysql --version 2>/dev/null | awk "{print \$3}" || echo ""',
            'mariadb' => '(mariadb --version 2>/dev/null || mysql --version 2>/dev/null) | head -n1',
            'postgres' => 'psql --version 2>/dev/null | awk "{print \$3}" || echo ""',
            'mongodb' => '(mongosh --version 2>/dev/null || mongo --version 2>/dev/null) | head -n1',
            'clickhouse' => 'clickhouse-client --version 2>/dev/null | head -n1',
            default => 'echo ""',
        };
    }

    public static function systemdServiceFor(string $engine): string
    {
        return match ($engine) {
            'mysql' => 'mysql',
            'mariadb' => 'mariadb',
            'postgres' => 'postgresql',
            'mongodb' => 'mongod',
            'clickhouse' => 'clickhouse-server',
            default => throw new \InvalidArgumentException("Unsupported database engine: {$engine}"),
        };
    }

    public static function configFilePathFor(string $engine): string
    {
        return match ($engine) {
            'mysql', 'mariadb' => '/etc/mysql/mariadb.conf.d/99-dply.cnf',
            'postgres' => '/etc/postgresql/main/postgresql.conf',
            'mongodb' => '/etc/mongod.conf',
            'clickhouse' => '/etc/clickhouse-server/config.xml',
            default => throw new \InvalidArgumentException("Unsupported database engine: {$engine}"),
        };
    }

    public static function defaultPortFor(string $engine): int
    {
        return match ($engine) {
            'postgres' => 5432,
            'mongodb' => 27017,
            'clickhouse' => 8123,
            'sqlite' => 0,
            default => 3306,
        };
    }

    /**
     * Bash that ensures the engine accepts TCP connections on 127.0.0.1 — the
     * address the deployed app (DB_HOST=127.0.0.1) and the binding connectivity
     * probe dial. A fresh install normally already binds localhost; this is the
     * remediation we run only when the post-install loopback check FAILS, so it
     * never clobbers a working config.
     *
     * Writes a LOW-numbered conf.d override (`00-dply-loopback`) so a later
     * remote-access override (`99-dply`, listen `*` / bind `0.0.0.0`) still wins —
     * both of which already include loopback. Returns '' for engines we don't
     * enforce here.
     */
    public static function ensureLoopbackListeningScript(string $engine): string
    {
        return match (true) {
            $engine === 'postgres' => <<<'BASH'
set -e
PG_VER=$(pg_lsclusters --no-header 2>/dev/null | awk '{print $1}' | sort -rn | head -1)
if [ -z "$PG_VER" ]; then PG_VER=$(ls /etc/postgresql/ 2>/dev/null | sort -rn | head -1); fi
if [ -z "$PG_VER" ]; then echo "[dply] ERROR: could not detect PostgreSQL version" >&2; exit 1; fi
CONF_DIR="/etc/postgresql/${PG_VER}/main/conf.d"
mkdir -p "${CONF_DIR}"
cat > "${CONF_DIR}/00-dply-loopback.conf" <<'EOF'
listen_addresses = 'localhost'
EOF
systemctl restart postgresql
echo "loopback_listen_ensured"
BASH,
            in_array($engine, ['mysql', 'mariadb'], true) => <<<'BASH'
set -e
for d in /etc/mysql/mysql.conf.d /etc/mysql/mariadb.conf.d; do
  if [ -d "$d" ]; then
    cat > "$d/00-dply-loopback.cnf" <<'EOF'
[mysqld]
bind-address = 127.0.0.1
EOF
  fi
done
systemctl restart mysql 2>/dev/null || systemctl restart mariadb
echo "loopback_listen_ensured"
BASH,
            default => '',
        };
    }

    /**
     * Bash that enables remote access for a specific database (pg_hba / mysql user grant).
     *
     * Postgres: ensures listen_addresses = '*', then upserts a pg_hba.conf rule scoped
     *   to the named database tagged # dply-db-{dbname}. Restarts postgres.
     *
     * MySQL/MariaDB: sets bind-address = 0.0.0.0, restarts, then re-GRANTs the
     *   database user from the given CIDR host pattern.
     */
    public static function enableDatabaseRemoteAccessScript(
        string $engine,
        string $dbName,
        string $dbUser,
        string $allowedCidr,
    ): string {
        $escapedDb = escapeshellarg($dbName);
        $escapedUser = escapeshellarg($dbUser);
        $escapedCidr = escapeshellarg($allowedCidr);
        $tag = 'dply-db-'.preg_replace('/[^a-zA-Z0-9_-]/', '_', $dbName);
        $escapedTag = escapeshellarg($tag);

        return match (true) {
            $engine === 'postgres' => <<<BASH
set -e
PG_VER=\$(pg_lsclusters --no-header 2>/dev/null | awk '{print \$1}' | sort -rn | head -1)
if [ -z "\$PG_VER" ]; then
  PG_VER=\$(ls /etc/postgresql/ 2>/dev/null | sort -rn | head -1)
fi
if [ -z "\$PG_VER" ]; then
  echo "[dply] ERROR: could not detect PostgreSQL version" >&2; exit 1
fi
CONF_DIR="/etc/postgresql/\${PG_VER}/main/conf.d"
HBA="/etc/postgresql/\${PG_VER}/main/pg_hba.conf"
mkdir -p "\${CONF_DIR}"
cat > "\${CONF_DIR}/99-dply.conf" <<'EOF'
listen_addresses = '*'
shared_buffers = '256MB'
max_connections = 200
EOF
DB={$escapedDb}
CIDR={$escapedCidr}
TAG={$escapedTag}
sed -i "/# \${TAG}\$/d" "\${HBA}"
echo "host \${DB} all \${CIDR} scram-sha-256 # \${TAG}" >> "\${HBA}"
systemctl restart postgresql
echo "database_remote_access_enabled"
BASH,
            in_array($engine, ['mysql', 'mariadb'], true) => <<<BASH
set -e
cat > /etc/mysql/mariadb.conf.d/99-dply.cnf <<'EOF'
[mysqld]
bind-address = 0.0.0.0
EOF
systemctl restart mysql 2>/dev/null || systemctl restart mariadb
DB={$escapedDb}
USER={$escapedUser}
CIDR={$escapedCidr}
HOST_PATTERN=\$(echo "\${CIDR}" | sed 's|/.*||')
mysql -u root -e "GRANT ALL PRIVILEGES ON \`\${DB}\`\.* TO '\${USER}'@'\${HOST_PATTERN}'; FLUSH PRIVILEGES;" 2>/dev/null || true
echo "database_remote_access_enabled"
BASH,
            default => throw new \InvalidArgumentException("Per-database remote access not supported for engine: {$engine}"),
        };
    }

    /**
     * Bash that removes remote access for a specific database.
     * Removes the pg_hba rule (postgres) or revokes the remote GRANT (mysql/mariadb).
     * Does NOT revert listen_addresses — other databases may still need remote access.
     */
    public static function disableDatabaseRemoteAccessScript(
        string $engine,
        string $dbName,
        string $dbUser,
    ): string {
        $tag = 'dply-db-'.preg_replace('/[^a-zA-Z0-9_-]/', '_', $dbName);
        $escapedTag = escapeshellarg($tag);
        $escapedDb = escapeshellarg($dbName);
        $escapedUser = escapeshellarg($dbUser);

        return match (true) {
            $engine === 'postgres' => <<<BASH
set -e
PG_VER=\$(pg_lsclusters --no-header 2>/dev/null | awk '{print \$1}' | sort -rn | head -1)
if [ -z "\$PG_VER" ]; then
  PG_VER=\$(ls /etc/postgresql/ 2>/dev/null | sort -rn | head -1)
fi
if [ -z "\$PG_VER" ]; then
  echo "[dply] ERROR: could not detect PostgreSQL version" >&2; exit 1
fi
HBA="/etc/postgresql/\${PG_VER}/main/pg_hba.conf"
TAG={$escapedTag}
sed -i "/# \${TAG}\$/d" "\${HBA}"
systemctl reload postgresql
echo "database_remote_access_disabled"
BASH,
            in_array($engine, ['mysql', 'mariadb'], true) => <<<BASH
set -e
DB={$escapedDb}
USER={$escapedUser}
mysql -u root -e "REVOKE ALL PRIVILEGES ON \`\${DB}\`\.* FROM '\${USER}'@'%'; FLUSH PRIVILEGES;" 2>/dev/null || true
echo "database_remote_access_disabled"
BASH,
            default => throw new \InvalidArgumentException("Per-database remote access not supported for engine: {$engine}"),
        };
    }

    /**
     * Bash that enables remote access for the given engine.
     *
     * Postgres: finds the active cluster version, writes listen_addresses = '*'
     *   to conf.d/99-dply.conf, and appends a scram-sha-256 pg_hba rule for the
     *   given CIDR (or 0.0.0.0/0 for open internet). Then restarts the service.
     *
     * MySQL/MariaDB: sets bind-address = 0.0.0.0 in the dply CNF override and
     *   restarts. Existing user grants remain host-specific — the operator must
     *   create/update users via the Databases workspace.
     */
    public static function enableRemoteAccessScript(string $engine, string $allowedCidr = '0.0.0.0/0'): string
    {
        $escapedCidr = escapeshellarg($allowedCidr);

        return match ($engine) {
            'postgres' => <<<BASH
set -e
PG_VER=\$(pg_lsclusters --no-header 2>/dev/null | awk '{print \$1}' | sort -rn | head -1)
if [ -z "\$PG_VER" ]; then
  PG_VER=\$(ls /etc/postgresql/ 2>/dev/null | sort -rn | head -1)
fi
if [ -z "\$PG_VER" ]; then
  echo "[dply] ERROR: could not detect PostgreSQL version" >&2; exit 1
fi
CONF_DIR="/etc/postgresql/\${PG_VER}/main/conf.d"
HBA="/etc/postgresql/\${PG_VER}/main/pg_hba.conf"
mkdir -p "\${CONF_DIR}"
cat > "\${CONF_DIR}/99-dply.conf" <<'EOF'
listen_addresses = '*'
shared_buffers = '256MB'
max_connections = 200
EOF
CIDR={$escapedCidr}
# Remove any existing dply-managed remote rule then append the new one
sed -i '/# dply-remote/d' "\${HBA}"
echo "host all all \${CIDR} scram-sha-256 # dply-remote" >> "\${HBA}"
systemctl restart postgresql
echo "remote_access_enabled"
BASH,
            'mysql', 'mariadb' => <<<'BASH'
set -e
cat > /etc/mysql/mariadb.conf.d/99-dply.cnf <<'EOF'
[mysqld]
bind-address = 0.0.0.0
EOF
systemctl restart mysql 2>/dev/null || systemctl restart mariadb
echo "remote_access_enabled"
BASH,
            // ClickHouse: make the server bind all interfaces so the HTTP (8123)
            // and native (9000) ports are reachable on the private network — the
            // network boundary is the UFW/cloud-firewall rule scoped to the
            // allowed CIDR (synced by ToggleDatabaseEngineRemoteAccessJob), NOT
            // ClickHouse auth, mirroring the mysql bind-address = 0.0.0.0 model.
            'clickhouse' => <<<'BASH'
set -e
mkdir -p /etc/clickhouse-server/config.d
cat > /etc/clickhouse-server/config.d/99-dply-listen.xml <<'EOF'
<clickhouse>
    <listen_host>0.0.0.0</listen_host>
</clickhouse>
EOF
chown clickhouse:clickhouse /etc/clickhouse-server/config.d/99-dply-listen.xml 2>/dev/null || true
systemctl restart clickhouse-server
echo "remote_access_enabled"
BASH,
            default => throw new \InvalidArgumentException("Remote access not supported for engine: {$engine}"),
        };
    }

    /**
     * Bash that reverts remote access: restores localhost-only binding and
     * removes the dply-managed pg_hba rule, then restarts the service.
     */
    public static function disableRemoteAccessScript(string $engine): string
    {
        return match ($engine) {
            'postgres' => <<<'BASH'
set -e
PG_VER=$(pg_lsclusters --no-header 2>/dev/null | awk '{print $1}' | sort -rn | head -1)
if [ -z "$PG_VER" ]; then
  PG_VER=$(ls /etc/postgresql/ 2>/dev/null | sort -rn | head -1)
fi
if [ -z "$PG_VER" ]; then
  echo "[dply] ERROR: could not detect PostgreSQL version" >&2; exit 1
fi
CONF_DIR="/etc/postgresql/${PG_VER}/main/conf.d"
HBA="/etc/postgresql/${PG_VER}/main/pg_hba.conf"
mkdir -p "${CONF_DIR}"
cat > "${CONF_DIR}/99-dply.conf" <<'EOF'
listen_addresses = '127.0.0.1'
shared_buffers = '256MB'
max_connections = 200
EOF
sed -i '/# dply-remote/d' "${HBA}"
systemctl restart postgresql
echo "remote_access_disabled"
BASH,
            'mysql', 'mariadb' => <<<'BASH'
set -e
cat > /etc/mysql/mariadb.conf.d/99-dply.cnf <<'EOF'
[mysqld]
bind-address = 127.0.0.1
EOF
systemctl restart mysql 2>/dev/null || systemctl restart mariadb
echo "remote_access_disabled"
BASH,
            'clickhouse' => <<<'BASH'
set -e
mkdir -p /etc/clickhouse-server/config.d
cat > /etc/clickhouse-server/config.d/99-dply-listen.xml <<'EOF'
<clickhouse>
    <listen_host>127.0.0.1</listen_host>
    <listen_host>::1</listen_host>
</clickhouse>
EOF
chown clickhouse:clickhouse /etc/clickhouse-server/config.d/99-dply-listen.xml 2>/dev/null || true
systemctl restart clickhouse-server
echo "remote_access_disabled"
BASH,
            default => throw new \InvalidArgumentException("Remote access not supported for engine: {$engine}"),
        };
    }

    /**
     * Engine-level remote access (server binds all interfaces; the firewall
     * scopes the source CIDR). ClickHouse is included — its logs-store use case
     * is the whole reason. {@see enableRemoteAccessScript}.
     */
    public static function supportsRemoteAccess(string $engine): bool
    {
        return in_array($engine, ['postgres', 'mysql', 'mariadb', 'clickhouse'], true);
    }

    /**
     * Per-DATABASE remote access (a single database/user grant scoped to a CIDR
     * via pg_hba / a host-specific MySQL GRANT). Narrower than the engine-level
     * toggle: ClickHouse and Mongo expose access at the server level only, so
     * they're excluded here even though ClickHouse supports the engine-level form.
     * {@see enableDatabaseRemoteAccessScript}.
     */
    public static function supportsPerDatabaseRemoteAccess(string $engine): bool
    {
        return in_array($engine, ['postgres', 'mysql', 'mariadb'], true);
    }

    /**
     * Start the engine and enable it at boot. The mysql/mariadb unit name
     * varies by distro/package, so try both when either family is requested.
     */
    public static function activateScript(string $engine): string
    {
        $unit = self::systemdServiceFor($engine);
        $alt = self::altSystemdServiceFor($engine);
        $enable = $alt !== null
            ? "systemctl enable --now {$unit} 2>/dev/null || systemctl enable --now {$alt}"
            : "systemctl enable --now {$unit}";
        $check = $alt !== null
            ? "systemctl is-active --quiet {$unit} || systemctl is-active --quiet {$alt}"
            : "systemctl is-active --quiet {$unit}";

        return <<<BASH
set -e
{$enable}
{$check}
echo "activated"
BASH;
    }

    /**
     * Stop the engine and disable it at boot — the daemon stays installed (data
     * and binaries untouched) but won't come back after a reboot until activated.
     */
    public static function deactivateScript(string $engine): string
    {
        $unit = self::systemdServiceFor($engine);
        $alt = self::altSystemdServiceFor($engine);
        $disable = $alt !== null
            ? "systemctl disable --now {$unit} 2>/dev/null || true\nsystemctl disable --now {$alt} 2>/dev/null || true"
            : "systemctl disable --now {$unit} 2>/dev/null || true";

        return <<<BASH
set -e
{$disable}
echo "deactivated"
BASH;
    }

    /** The alternate systemd unit name for mysql/mariadb (the two are interchangeable per distro). */
    private static function altSystemdServiceFor(string $engine): ?string
    {
        return match ($engine) {
            'mysql' => 'mariadb',
            'mariadb' => 'mysql',
            default => null,
        };
    }

    public static function timescaledbRepoBootstrapScript(): string
    {
        return <<<'BASH'
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y gnupg curl ca-certificates lsb-release
curl -fsSL https://packagecloud.io/timescale/timescaledb/gpgkey | gpg --dearmor -o /usr/share/keyrings/timescaledb.gpg
ARCH=$(dpkg --print-architecture)
CODENAME=$(lsb_release -cs 2>/dev/null || echo jammy)
echo "deb [signed-by=/usr/share/keyrings/timescaledb.gpg arch=${ARCH}] https://packagecloud.io/timescale/timescaledb/ubuntu/ ${CODENAME} main" > /etc/apt/sources.list.d/timescaledb.list
apt-get update -y
BASH;
    }
}
