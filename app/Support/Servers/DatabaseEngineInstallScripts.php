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
