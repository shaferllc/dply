<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Services\Servers\ServerProvisionCommandBuilder;

/**
 * Database-engine-side mirror of {@see CacheServiceInstallScripts}. Holds the install / uninstall
 * / version-probe / config-path bash for the engines the workspace can manage at runtime
 * (mysql/mariadb/postgres). The actual apt-install bash is the same shape as what
 * {@see ServerProvisionCommandBuilder} runs at server-build time, just
 * extracted so the install/uninstall jobs don't re-enter the full role flow.
 *
 * The supported list is a *runtime-installable* subset. SQLite isn't here — it's just a binary
 * that gets installed alongside any of the others; there's nothing to "install" as an engine.
 */
final class DatabaseEngineInstallScripts
{
    /**
     * @return list<string>
     */
    public static function supportedEngines(): array
    {
        // Limited to the variants we've validated apt-install scripts for. Operators picking
        // exact upstream versions (mysql80 vs mysql84 etc.) can do that at provision time;
        // post-provision, the workspace surfaces these latest-stable choices to keep the UX
        // simple. New variants are easy to add by extending the match arms below.
        return ['mysql', 'mariadb', 'postgres'];
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
            default => throw new \InvalidArgumentException("Unsupported database engine: {$engine}"),
        };
    }

    /**
     * Best-effort version detection — returns "" when the CLI tool isn't on PATH (the install
     * job leaves `version` null in that case).
     */
    public static function versionProbeScript(string $engine): string
    {
        return match ($engine) {
            'mysql' => 'mysql --version 2>/dev/null | awk "{print \$3}" || echo ""',
            'mariadb' => '(mariadb --version 2>/dev/null || mysql --version 2>/dev/null) | head -n1',
            'postgres' => 'psql --version 2>/dev/null | awk "{print \$3}" || echo ""',
            default => 'echo ""',
        };
    }

    public static function systemdServiceFor(string $engine): string
    {
        return match ($engine) {
            'mysql' => 'mysql',
            'mariadb' => 'mariadb',
            'postgres' => 'postgresql',
            default => throw new \InvalidArgumentException("Unsupported database engine: {$engine}"),
        };
    }

    /**
     * Path to the engine's main config file. Used by the read-only viewer (Phase 3) and by any
     * future tuning forms. MySQL/MariaDB share the Debian conf-dir layout; Postgres ships its
     * config inside the version-specific directory under /etc/postgresql/.
     */
    public static function configFilePathFor(string $engine): string
    {
        return match ($engine) {
            'mysql', 'mariadb' => '/etc/mysql/mariadb.conf.d/99-dply.cnf',
            'postgres' => '/etc/postgresql/main/postgresql.conf', // resolved per-version at read time
            default => throw new \InvalidArgumentException("Unsupported database engine: {$engine}"),
        };
    }

    public static function defaultPortFor(string $engine): int
    {
        return match ($engine) {
            'postgres' => 5432,
            default => 3306,
        };
    }
}
