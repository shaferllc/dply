<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Database-host options from the server create wizard (remote access, initial
 * database name, application user + password). Stored on {@see Server::$meta}
 * under `database_server` and consumed by {@see ServerProvisionCommandBuilder}
 * during first-run provision, then mirrored into {@see ServerDatabase} and a
 * panel firewall rule by {@see SeedProvisionedEnginesForServer}.
 */
final class DedicatedDatabaseServerProvisionConfig
{
    public function __construct(
        public readonly string $wizardDatabase,
        public readonly bool $remoteAccess,
        public readonly string $allowedFrom,
        public readonly string $databaseName,
        public readonly string $username,
        public readonly ?string $password,
    ) {}

    public static function fromServer(?Server $server, string $wizardDatabase): self
    {
        $wizardDatabase = trim($wizardDatabase) === '' || $wizardDatabase === 'none'
            ? 'postgres17'
            : trim($wizardDatabase);

        if ($server === null) {
            return self::localhostDefaults($wizardDatabase);
        }

        $meta = $server->meta ?? [];
        $databaseServer = $meta['database_server'] ?? null;
        if (! is_array($databaseServer)) {
            return self::localhostDefaults($wizardDatabase);
        }

        $password = null;
        $encrypted = $databaseServer['password_encrypted'] ?? null;
        if (is_string($encrypted) && $encrypted !== '') {
            try {
                $password = Crypt::decryptString($encrypted);
            } catch (DecryptException) {
                $password = null;
            }
        }

        return new self(
            $wizardDatabase,
            (bool) ($databaseServer['remote_access'] ?? false),
            trim((string) ($databaseServer['allowed_from'] ?? '')),
            trim((string) ($databaseServer['database_name'] ?? 'app')),
            trim((string) ($databaseServer['username'] ?? 'dply_app')),
            $password,
        );
    }

    public static function localhostDefaults(string $wizardDatabase): self
    {
        return new self($wizardDatabase, false, '', 'app', 'dply_app', null);
    }

    public static function engineFamily(string $wizardDatabase): string
    {
        if (str_starts_with($wizardDatabase, 'postgres')) {
            return 'postgres';
        }

        if (str_starts_with($wizardDatabase, 'mysql')) {
            return 'mysql';
        }

        if (str_starts_with($wizardDatabase, 'mariadb')) {
            return 'mariadb';
        }

        if ($wizardDatabase === 'sqlite3') {
            return 'sqlite';
        }

        return $wizardDatabase;
    }

    public static function engineSupportsRemoteAccess(string $wizardDatabase): bool
    {
        // ClickHouse included so a dedicated logs-store box can be created with
        // private-network access in one shot. Mongo stays localhost-only.
        return in_array(self::engineFamily($wizardDatabase), ['postgres', 'mysql', 'mariadb', 'clickhouse'], true);
    }

    public static function supportsBootstrapCredentials(string $wizardDatabase): bool
    {
        return self::engineFamily($wizardDatabase) !== 'sqlite';
    }

    public static function isAllowedSourceCidr(string $source): bool
    {
        return DedicatedCacheServerProvisionConfig::isAllowedSourceCidr($source);
    }

    public function defaultPort(): int
    {
        return match (self::engineFamily($this->wizardDatabase)) {
            'postgres' => 5432,
            'mysql', 'mariadb' => 3306,
            'mongodb' => 27017,
            'clickhouse' => 8123,
            default => 5432,
        };
    }

    /**
     * Ports to open for remote access. ClickHouse needs BOTH its HTTP (8123) and
     * native (9000) ports for a usable logs store; everything else is single-port.
     *
     * @return list<int>
     */
    public function remoteAccessPorts(): array
    {
        return self::engineFamily($this->wizardDatabase) === 'clickhouse'
            ? [8123, 9000]
            : [$this->defaultPort()];
    }

    /**
     * @return list<string>
     */
    public function ufwAllowLines(): array
    {
        if (! $this->remoteAccess || ! self::engineSupportsRemoteAccess($this->wizardDatabase)) {
            return [];
        }

        if (! self::isAllowedSourceCidr($this->allowedFrom)) {
            return [];
        }

        $lines = [];
        foreach (DedicatedCacheServerProvisionConfig::splitAllowedFrom($this->allowedFrom) as $cidr) {
            foreach ($this->remoteAccessPorts() as $port) {
                $lines[] = 'ufw allow from '.escapeshellarg($cidr).' to any port '.$port.' proto tcp';
            }
        }

        return $lines;
    }

    /**
     * Network tuning + initial database/user bootstrap after the engine packages
     * are installed. No-op when credentials are incomplete.
     *
     * @return list<string>
     */
    public function bootstrapLines(): array
    {
        if (! self::supportsBootstrapCredentials($this->wizardDatabase)) {
            return [];
        }

        if ($this->databaseName === '' || $this->username === '' || $this->password === null || $this->password === '') {
            return [];
        }

        if (! preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,62}$/', $this->databaseName)) {
            return [];
        }

        if (! preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,62}$/', $this->username)) {
            return [];
        }

        return match (self::engineFamily($this->wizardDatabase)) {
            'postgres' => $this->postgresBootstrapLines(),
            'mysql', 'mariadb' => $this->mysqlBootstrapLines(),
            'clickhouse' => $this->clickhouseBootstrapLines(),
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    private function postgresBootstrapLines(): array
    {
        $ver = match ($this->wizardDatabase) {
            'postgres14' => '14',
            'postgres15' => '15',
            'postgres16' => '16',
            'postgres17' => '17',
            'postgres18' => '18',
            default => '16',
        };

        $listen = $this->remoteAccess ? '*' : '127.0.0.1';
        $confPath = '/etc/postgresql/'.$ver.'/main/conf.d/99-dply.conf';
        $hbaPath = '/etc/postgresql/'.$ver.'/main/pg_hba.conf';

        $lines = [
            $this->writeFileWithRollback($confPath, "listen_addresses = '{$listen}'\nshared_buffers = '256MB'\nmax_connections = 200\n"),
        ];

        if ($this->remoteAccess && self::isAllowedSourceCidr($this->allowedFrom)) {
            foreach (DedicatedCacheServerProvisionConfig::splitAllowedFrom($this->allowedFrom) as $cidr) {
                $lines[] = 'grep -Fq '.escapeshellarg("host all all {$cidr} scram-sha-256").' '.escapeshellarg($hbaPath)
                    .' || echo '.escapeshellarg("host all all {$cidr} scram-sha-256").' >> '.escapeshellarg($hbaPath);
            }
        }

        $lines[] = 'systemctl restart postgresql || true';

        $userSql = "DO \$\$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '{$this->username}') THEN CREATE ROLE {$this->username} LOGIN PASSWORD '".str_replace("'", "''", $this->password)."'; END IF; END \$\$;";
        $dbSql = "SELECT 1 FROM pg_database WHERE datname = '{$this->databaseName}'";
        $createDbSql = "CREATE DATABASE {$this->databaseName} OWNER {$this->username};";

        $lines[] = 'sudo -u postgres psql -v ON_ERROR_STOP=1 -c '.escapeshellarg($userSql);
        $lines[] = 'sudo -u postgres psql -tAc '.escapeshellarg($dbSql).' | grep -q 1 || sudo -u postgres psql -v ON_ERROR_STOP=1 -c '.escapeshellarg($createDbSql);

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function mysqlBootstrapLines(): array
    {
        $bind = $this->remoteAccess ? '0.0.0.0' : '127.0.0.1';
        $confPath = str_starts_with($this->wizardDatabase, 'mariadb')
            ? '/etc/mysql/mariadb.conf.d/99-dply.cnf'
            : '/etc/mysql/mysql.conf.d/99-dply.cnf';

        $lines = [
            $this->writeFileWithRollback($confPath, "[mysqld]\nbind-address = {$bind}\nmax_connections = 200\ninnodb_buffer_pool_size = 256M\n"),
            'systemctl restart mysql 2>/dev/null || systemctl restart mariadb 2>/dev/null || true',
        ];

        $passSql = str_replace(['\\', "'"], ['\\\\', "\\'"], $this->password);
        $host = $this->remoteAccess ? '%' : 'localhost';
        $sql = "CREATE DATABASE IF NOT EXISTS `{$this->databaseName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; "
            ."CREATE USER IF NOT EXISTS '{$this->username}'@'{$host}' IDENTIFIED BY '{$passSql}'; "
            ."GRANT ALL PRIVILEGES ON `{$this->databaseName}`.* TO '{$this->username}'@'{$host}'; FLUSH PRIVILEGES;";

        $lines[] = 'mysql -e '.escapeshellarg($sql);

        return $lines;
    }

    /**
     * ClickHouse network bind + initial database/user bootstrap. Binds all
     * interfaces when remote access is on (the UFW rule is the boundary; see
     * ufwAllowLines), waits for the server to accept queries, then creates the
     * database and a password user. Database/user names are pre-validated by
     * bootstrapLines(); the password is escaped for the SQL string literal and
     * the whole statement is shell-quoted.
     *
     * @return list<string>
     */
    private function clickhouseBootstrapLines(): array
    {
        $listen = $this->remoteAccess
            ? "<clickhouse>\n    <listen_host>0.0.0.0</listen_host>\n</clickhouse>\n"
            : "<clickhouse>\n    <listen_host>127.0.0.1</listen_host>\n    <listen_host>::1</listen_host>\n</clickhouse>\n";

        $passSql = str_replace(['\\', "'"], ['\\\\', "\\'"], $this->password);
        $sql = "CREATE DATABASE IF NOT EXISTS `{$this->databaseName}`; "
            ."CREATE USER IF NOT EXISTS `{$this->username}` IDENTIFIED WITH sha256_password BY '{$passSql}'; "
            ."GRANT ALL ON `{$this->databaseName}`.* TO `{$this->username}`;";

        return [
            $this->writeFileWithRollback('/etc/clickhouse-server/config.d/99-dply-listen.xml', $listen),
            'systemctl restart clickhouse-server || true',
            // Wait for the HTTP/native interface to accept queries before bootstrapping.
            'for i in $(seq 1 30); do clickhouse-client --query "SELECT 1" >/dev/null 2>&1 && break; sleep 2; done',
            'clickhouse-client --multiquery --query '.escapeshellarg($sql),
        ];
    }

    private function writeFileWithRollback(string $path, string $content): string
    {
        return 'dply_write_file '.escapeshellarg(base64_encode($path)).' '.escapeshellarg(base64_encode($content));
    }
}
