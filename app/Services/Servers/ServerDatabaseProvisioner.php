<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseExtraUser;
use Illuminate\Support\Str;

class ServerDatabaseProvisioner
{
    public function __construct(
        protected ServerDatabaseRemoteExec $remoteExec
    ) {}

    /**
     * @return list<string>
     */
    public function listMysqlDatabaseNames(Server $server): array
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        [$out, $exit] = $this->remoteExec->mysqlRunWithExit($server, 'SHOW DATABASES', 90);
        $out = trim($out);
        if ($exit !== null && $exit !== 0) {
            throw new \RuntimeException('Could not list MySQL databases: '.Str::limit($out, 800));
        }

        $system = [
            'information_schema',
            'mysql',
            'performance_schema',
            'sys',
        ];

        $names = [];
        foreach (preg_split("/\r\n|\n|\r/", $out) as $line) {
            $line = trim($line);
            if ($line === '' || in_array($line, $system, true)) {
                continue;
            }
            $names[] = $line;
        }

        sort($names);

        return array_values(array_unique($names));
    }

    /**
     * @return list<string>
     */
    public function listPostgresDatabaseNames(Server $server): array
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $sql = 'SELECT datname FROM pg_database WHERE datistemplate = false AND datname NOT IN (\'postgres\') ORDER BY datname';
        [$out, $exit] = $this->remoteExec->postgresTuples($server, $sql, 90);
        $out = trim($out);
        if ($exit !== null && $exit !== 0) {
            throw new \RuntimeException('Could not list PostgreSQL databases: '.Str::limit($out, 800));
        }

        $names = [];
        foreach (preg_split("/\r\n|\n|\r/", $out) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $names[] = $line;
            }
        }

        return array_values(array_unique($names));
    }

    public function dropFromServer(ServerDatabase $db): string
    {
        $server = $db->server;
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $name = $db->name;
        $user = $db->username;

        if ($db->engine === 'postgres') {
            $terminate = "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '".str_replace("'", "''", $name)."' AND pid <> pg_backend_pid();";
            [$out] = $this->remoteExec->postgresRun($server, $terminate, 120);
            $dropDb = 'DROP DATABASE IF EXISTS '.$name.';';
            [$out2] = $this->remoteExec->postgresRun($server, $dropDb, 120);
            $dropUser = 'DROP USER IF EXISTS '.$user.';';
            [$out3] = $this->remoteExec->postgresRun($server, $dropUser, 120);

            return $out."\n".$out2."\n".$out3;
        }

        $sql =
            'DROP DATABASE IF EXISTS `'.str_replace('`', '``', $name).'`; '.
            'DROP USER IF EXISTS \''.str_replace(['\\', "'"], ['\\\\', "\\'"], $user).'\'@\'localhost\'; '.
            'FLUSH PRIVILEGES;';

        [$out] = $this->remoteExec->mysqlRunWithExit($server, $sql, 120);

        return $out;
    }

    public function createOnServer(ServerDatabase $db): string
    {
        $server = $db->server;
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $name = $db->name;
        $user = $db->username;
        $pass = $db->password;

        if ($db->engine === 'postgres') {
            $userSql = "CREATE USER {$user} WITH PASSWORD '".str_replace("'", "''", $pass)."';";
            [$out] = $this->remoteExec->postgresRun($server, $userSql, 120);
            $dbSql = "CREATE DATABASE {$name} OWNER {$user};";

            return $out."\n".$this->remoteExec->postgresRun($server, $dbSql, 120)[0];
        }

        $charset = $this->sanitizeMysqlIdentifier((string) ($db->mysql_charset ?: 'utf8mb4'), 'utf8mb4');
        $coll = $this->sanitizeMysqlIdentifier((string) ($db->mysql_collation ?: 'utf8mb4_unicode_ci'), 'utf8mb4_unicode_ci');

        $passSql = str_replace(['\\', "'"], ['\\\\', "\\'"], $pass);
        $sql =
            "CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET {$charset} COLLATE {$coll}; ".
            "CREATE USER IF NOT EXISTS '{$user}'@'localhost' IDENTIFIED BY '{$passSql}'; ".
            "GRANT ALL PRIVILEGES ON `{$name}`.* TO '{$user}'@'localhost'; FLUSH PRIVILEGES;";

        [$out] = $this->remoteExec->mysqlRunWithExit($server, $sql, 120);

        return $out;
    }

    /**
     * Grant an additional MySQL user on an existing database.
     */
    public function createExtraMysqlUser(ServerDatabase $db, ServerDatabaseExtraUser $extra): string
    {
        if ($db->engine !== 'mysql') {
            throw new \InvalidArgumentException('Extra users are implemented for MySQL in this release.');
        }

        $server = $db->server;
        $name = $db->name;
        $u = $extra->username;
        $h = $extra->host ?: 'localhost';
        $pass = str_replace(['\\', "'"], ['\\\\', "\\'"], $extra->password);
        $sql =
            "CREATE USER IF NOT EXISTS '{$u}'@'{$h}' IDENTIFIED BY '{$pass}'; ".
            "GRANT ALL PRIVILEGES ON `{$name}`.* TO '{$u}'@'{$h}'; FLUSH PRIVILEGES;";

        [$out, $exit] = $this->remoteExec->mysqlRunWithExit($server, $sql, 120);
        if ($exit !== null && $exit !== 0) {
            throw new \RuntimeException(Str::limit($out, 800));
        }

        return $out;
    }

    /**
     * Drop an extra MySQL user created for this database (matches {@see createExtraMysqlUser}).
     */
    public function dropExtraMysqlUser(ServerDatabase $db, ServerDatabaseExtraUser $extra): string
    {
        if ($db->engine !== 'mysql') {
            throw new \InvalidArgumentException('Only MySQL extra users are supported.');
        }

        $server = $db->server;
        $u = str_replace(['\\', "'"], ['\\\\', "\\'"], $extra->username);
        $h = str_replace(['\\', "'"], ['\\\\', "\\'"], $extra->host ?: 'localhost');
        $sql = "DROP USER IF EXISTS '{$u}'@'{$h}'; FLUSH PRIVILEGES;";

        [$out, $exit] = $this->remoteExec->mysqlRunWithExit($server, $sql, 120);
        if ($exit !== null && $exit !== 0) {
            throw new \RuntimeException(Str::limit($out, 800));
        }

        return $out;
    }

    private function sanitizeMysqlIdentifier(string $value, string $fallback): string
    {
        if ($value !== '' && preg_match('/^[a-zA-Z0-9_]+$/', $value)) {
            return $value;
        }

        return $fallback;
    }
}
