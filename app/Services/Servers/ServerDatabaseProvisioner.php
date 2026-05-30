<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseExtraUser;
use App\Support\Servers\DatabaseWorkspaceEngines;
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

    /**
     * @return list<string>
     */
    public function listMongodbDatabaseNames(Server $server): array
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $js = "print(JSON.stringify(db.adminCommand('listDatabases').databases.map(d => d.name).filter(n => !['admin','local','config'].includes(n))))";
        [$out, $exit] = $this->remoteExec->mongoshRunWithExit($server, $js, 90);
        $out = trim($out);
        if ($exit !== null && $exit !== 0) {
            throw new \RuntimeException('Could not list MongoDB databases: '.Str::limit($out, 800));
        }

        $decoded = json_decode($out, true);
        if (! is_array($decoded)) {
            return [];
        }

        $names = array_values(array_filter(array_map('strval', $decoded)));

        sort($names);

        return array_values(array_unique($names));
    }

    /**
     * @return list<string>
     */
    public function listClickhouseDatabaseNames(Server $server): array
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $sql = "SELECT name FROM system.databases WHERE name NOT IN ('system','INFORMATION_SCHEMA','information_schema','default') ORDER BY name FORMAT TabSeparated";
        [$out, $exit] = $this->remoteExec->clickhouseRunWithExit($server, $sql, 90);
        $out = trim($out);
        if ($exit !== null && $exit !== 0) {
            throw new \RuntimeException('Could not list ClickHouse databases: '.Str::limit($out, 800));
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

        if ($db->engine === 'sqlite') {
            // SQLite "drop" is just removing the file. The path lives
            // in the host column (set when the row was created). We
            // hard-fail if it points outside the configured root so a
            // typo or tampering can never wipe `/etc` or similar.
            $path = $this->safeSqlitePath($db);
            $cmd = 'rm -f '.escapeshellarg($path);
            [$out] = $this->remoteExec->shellRunWithExit($server, $cmd, 30);

            return $out[0];
        }

        if ($db->engine === 'postgres') {
            $terminate = "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '".str_replace("'", "''", $name)."' AND pid <> pg_backend_pid();";
            [$out] = $this->remoteExec->postgresRun($server, $terminate, 120);
            $dropDb = 'DROP DATABASE IF EXISTS '.$name.';';
            [$out2] = $this->remoteExec->postgresRun($server, $dropDb, 120);
            $dropUser = 'DROP USER IF EXISTS '.$user.';';
            [$out3] = $this->remoteExec->postgresRun($server, $dropUser, 120);

            return $out."\n".$out2."\n".$out3;
        }

        if ($db->engine === 'mongodb') {
            $dbName = $this->sanitizeMongoIdentifier($name);
            $user = $this->sanitizeMongoIdentifier($user);
            $js = "db.getSiblingDB('{$dbName}').dropDatabase(); db.getSiblingDB('admin').dropUser('{$user}');";
            [$out] = $this->remoteExec->mongoshRunWithExit($server, $js, 120);

            return $out[0];
        }

        if ($db->engine === 'clickhouse') {
            $dbName = $this->sanitizeClickhouseIdentifier($name);
            $user = $this->sanitizeClickhouseIdentifier($user);
            $sql = "DROP DATABASE IF EXISTS `{$dbName}`; DROP USER IF EXISTS `{$user}`;";
            [$out] = $this->remoteExec->clickhouseRunWithExit($server, $sql, 120);

            return $out[0];
        }

        if (DatabaseWorkspaceEngines::isMysqlFamily($db->engine)) {
            $sql =
                'DROP DATABASE IF EXISTS `'.str_replace('`', '``', $name).'`; '.
                'DROP USER IF EXISTS \''.str_replace(['\\', "'"], ['\\\\', "\\'"], $user).'\'@\'localhost\'; '.
                'FLUSH PRIVILEGES;';

            [$out] = $this->remoteExec->mysqlRunWithExit($server, $sql, 120);

            return $out[0];
        }

        throw new \InvalidArgumentException("Unsupported database engine for drop: {$db->engine}");
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

        if ($db->engine === 'sqlite') {
            // SQLite "create" is just `mkdir -p` the parent + `touch`
            // the file + chown. No auth, no cluster, no port. The path
            // is stored in the host column; we sanity-check it sits
            // under the configured root before any filesystem op.
            $path = $this->safeSqlitePath($db);
            $owner = $this->resolveSqliteOwner($db);
            $dir = dirname($path);

            $cmd = 'mkdir -p '.escapeshellarg($dir).' && '.
                   'touch '.escapeshellarg($path).' && '.
                   'chown '.escapeshellarg($owner.':'.$owner).' '.escapeshellarg($path).' 2>/dev/null || true; '.
                   'chmod 0664 '.escapeshellarg($path).' 2>/dev/null || true; '.
                   'echo "[dply] sqlite database ready at '.escapeshellarg($path).'"';

            [$out, $exit] = $this->remoteExec->shellRunWithExit($server, $cmd, 30);
            if ($exit !== null && $exit !== 0) {
                throw new \RuntimeException(Str::limit($out, 800));
            }

            return $out;
        }

        if ($db->engine === 'postgres') {
            $userSql = "CREATE USER {$user} WITH PASSWORD '".str_replace("'", "''", $pass)."';";
            [$out] = $this->remoteExec->postgresRun($server, $userSql, 120);
            $dbSql = "CREATE DATABASE {$name} OWNER {$user};";

            return $out."\n".$this->remoteExec->postgresRun($server, $dbSql, 120)[0];
        }

        if ($db->engine === 'mongodb') {
            $dbName = $this->sanitizeMongoIdentifier($name);
            $userName = $this->sanitizeMongoIdentifier($user);
            $passJs = json_encode($pass, JSON_THROW_ON_ERROR);
            $js = "const d='{$dbName}'; const u='{$userName}'; const p={$passJs}; ".
                "db.getSiblingDB(d).createCollection('_dply_init'); ".
                "db.getSiblingDB(d).createUser({user: u, pwd: p, roles: [{role: 'readWrite', db: d}]});";
            [$out, $exit] = $this->remoteExec->mongoshRunWithExit($server, $js, 120);
            if ($exit !== null && $exit !== 0) {
                throw new \RuntimeException(Str::limit($out, 800));
            }

            return $out;
        }

        if ($db->engine === 'clickhouse') {
            $dbName = $this->sanitizeClickhouseIdentifier($name);
            $userName = $this->sanitizeClickhouseIdentifier($user);
            $passSql = str_replace("'", "\\'", $pass);
            $sql = "CREATE DATABASE IF NOT EXISTS `{$dbName}`; ".
                "CREATE USER IF NOT EXISTS `{$userName}` IDENTIFIED BY '{$passSql}'; ".
                "GRANT ALL ON `{$dbName}`.* TO `{$userName}`;";
            [$out, $exit] = $this->remoteExec->clickhouseRunWithExit($server, $sql, 120);
            if ($exit !== null && $exit !== 0) {
                throw new \RuntimeException(Str::limit($out, 800));
            }

            return $out;
        }

        if (DatabaseWorkspaceEngines::isMysqlFamily($db->engine)) {
            $charset = $this->sanitizeMysqlIdentifier((string) ($db->mysql_charset ?: 'utf8mb4'), 'utf8mb4');
            $coll = $this->sanitizeMysqlIdentifier((string) ($db->mysql_collation ?: 'utf8mb4_unicode_ci'), 'utf8mb4_unicode_ci');

            $passSql = str_replace(['\\', "'"], ['\\\\', "\\'"], $pass);
            $sql =
                "CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET {$charset} COLLATE {$coll}; ".
                "CREATE USER IF NOT EXISTS '{$user}'@'localhost' IDENTIFIED BY '{$passSql}'; ".
                "GRANT ALL PRIVILEGES ON `{$name}`.* TO '{$user}'@'localhost'; FLUSH PRIVILEGES;";

            [$out] = $this->remoteExec->mysqlRunWithExit($server, $sql, 120);

            return $out[0];
        }

        throw new \InvalidArgumentException("Unsupported database engine for create: {$db->engine}");
    }

    public function createMysqlDatabaseForExistingUser(ServerDatabase $db, string $grantHost = 'localhost'): string
    {
        if (! DatabaseWorkspaceEngines::isMysqlFamily($db->engine)) {
            throw new \InvalidArgumentException('Existing user selection is currently supported for MySQL/MariaDB only.');
        }

        $server = $db->server;
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $name = $db->name;
        $user = str_replace(['\\', "'"], ['\\\\', "\\'"], $db->username);
        $host = str_replace(['\\', "'"], ['\\\\', "\\'"], $grantHost !== '' ? $grantHost : 'localhost');
        $charset = $this->sanitizeMysqlIdentifier((string) ($db->mysql_charset ?: 'utf8mb4'), 'utf8mb4');
        $coll = $this->sanitizeMysqlIdentifier((string) ($db->mysql_collation ?: 'utf8mb4_unicode_ci'), 'utf8mb4_unicode_ci');

        $sql =
            "CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET {$charset} COLLATE {$coll}; ".
            "GRANT ALL PRIVILEGES ON `{$name}`.* TO '{$user}'@'{$host}'; FLUSH PRIVILEGES;";

        [$out] = $this->remoteExec->mysqlRunWithExit($server, $sql, 120);

        return $out;
    }

    /**
     * Grant an additional MySQL user on an existing database.
     */
    public function createExtraMysqlUser(ServerDatabase $db, ServerDatabaseExtraUser $extra): string
    {
        if (! DatabaseWorkspaceEngines::isMysqlFamily($db->engine)) {
            throw new \InvalidArgumentException('Extra users are implemented for MySQL/MariaDB in this release.');
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
        if (! DatabaseWorkspaceEngines::isMysqlFamily($db->engine)) {
            throw new \InvalidArgumentException('Only MySQL/MariaDB extra users are supported.');
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

    /**
     * Engine-agnostic dispatcher: routes the extra-user create call to
     * the appropriate per-engine implementation. Lets the workspace
     * manage extra users on PostgreSQL databases the same way it does
     * for MySQL/MariaDB instead of throwing "MySQL only" mid-flow.
     */
    public function createExtraDatabaseUser(ServerDatabase $db, ServerDatabaseExtraUser $extra): string
    {
        return match ($db->engine) {
            'postgres' => $this->createExtraPostgresUser($db, $extra),
            'mysql', 'mariadb' => $this->createExtraMysqlUser($db, $extra),
            default => throw new \InvalidArgumentException('Extra users are supported for MySQL, MariaDB, and PostgreSQL only.'),
        };
    }

    public function dropExtraDatabaseUser(ServerDatabase $db, ServerDatabaseExtraUser $extra): string
    {
        return match ($db->engine) {
            'postgres' => $this->dropExtraPostgresUser($db, $extra),
            'mysql', 'mariadb' => $this->dropExtraMysqlUser($db, $extra),
            default => throw new \InvalidArgumentException('Extra users are supported for MySQL, MariaDB, and PostgreSQL only.'),
        };
    }

    /**
     * Grant an additional PostgreSQL user on an existing database.
     * Mirrors {@see createExtraMysqlUser} but uses Postgres semantics:
     * roles are global (no @host), GRANT is on the database object.
     */
    public function createExtraPostgresUser(ServerDatabase $db, ServerDatabaseExtraUser $extra): string
    {
        if ($db->engine !== 'postgres') {
            throw new \InvalidArgumentException('createExtraPostgresUser called on a non-postgres database.');
        }

        $server = $db->server;
        $name = $this->sanitizePostgresIdentifier($db->name, 'app');
        $user = $this->sanitizePostgresIdentifier($extra->username, 'extrauser');
        $pass = str_replace("'", "''", $extra->password);

        $sql = "CREATE USER {$user} WITH PASSWORD '{$pass}'; ".
               "GRANT CONNECT ON DATABASE {$name} TO {$user}; ".
               "GRANT ALL PRIVILEGES ON DATABASE {$name} TO {$user};";

        [$out] = $this->remoteExec->postgresRun($server, $sql, 120);

        return $out;
    }

    /**
     * Drop an extra PostgreSQL user. Revokes grants first so DROP USER
     * doesn't fail with "role cannot be dropped because some objects
     * depend on it" when the user owns nothing concrete.
     */
    public function dropExtraPostgresUser(ServerDatabase $db, ServerDatabaseExtraUser $extra): string
    {
        if ($db->engine !== 'postgres') {
            throw new \InvalidArgumentException('dropExtraPostgresUser called on a non-postgres database.');
        }

        $server = $db->server;
        $name = $this->sanitizePostgresIdentifier($db->name, 'app');
        $user = $this->sanitizePostgresIdentifier($extra->username, 'extrauser');

        $sql = "REVOKE ALL PRIVILEGES ON DATABASE {$name} FROM {$user}; ".
               "REVOKE CONNECT ON DATABASE {$name} FROM {$user}; ".
               "DROP USER IF EXISTS {$user};";

        [$out] = $this->remoteExec->postgresRun($server, $sql, 120);

        return $out;
    }

    /**
     * Move a SQLite database file from its current `host` path to a
     * new path on the server. Both paths are jailed under
     * `config('server_database.sqlite_root')` via {@see safeSqlitePath()}.
     * The Livewire caller is responsible for updating the row's `host`
     * column AFTER this method returns successfully — that ordering
     * means a failed `mv` doesn't leave the dashboard pointing at a
     * file that no longer exists at the recorded path.
     *
     * Idempotent when old == new: returns a noop status string without
     * touching the filesystem.
     */
    public function relocateSqliteFile(ServerDatabase $db, string $newPath): string
    {
        if ($db->engine !== 'sqlite') {
            throw new \InvalidArgumentException('relocateSqliteFile called on a non-sqlite database.');
        }

        $server = $db->server;
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $oldPath = $this->safeSqlitePath($db);

        // safeSqlitePath() reads from $db->host. Build a temporary
        // db clone with the new host so the same validator covers the
        // destination — same path-jail rules, no second implementation.
        $probe = $db->replicate();
        $probe->host = $newPath;
        $resolved = $this->safeSqlitePath($probe);

        if ($oldPath === $resolved) {
            return '[dply] sqlite path unchanged at '.$oldPath;
        }

        $owner = $this->resolveSqliteOwner($db);
        $cmd = 'mkdir -p '.escapeshellarg(dirname($resolved)).' && '.
               'mv '.escapeshellarg($oldPath).' '.escapeshellarg($resolved).' && '.
               'chown '.escapeshellarg($owner.':'.$owner).' '.escapeshellarg($resolved).' 2>/dev/null || true; '.
               'echo "[dply] moved '.escapeshellarg($oldPath).' -> '.escapeshellarg($resolved).'"';

        [$out, $exit] = $this->remoteExec->shellRunWithExit($server, $cmd, 60);
        if ($exit !== null && $exit !== 0) {
            throw new \RuntimeException(Str::limit($out, 800));
        }

        return $out;
    }

    /**
     * Run arbitrary SQL against a SQLite database file. Statement size
     * is capped at the same `import_max_bytes` ceiling MySQL/Postgres
     * imports use so a paste-of-doom can't OOM the host. Path goes
     * through {@see safeSqlitePath()} as belt-and-suspenders even
     * though the caller already validated on create.
     *
     * Returns the trimmed stdout/stderr — sqlite3 writes both to
     * stdout when invoked with the heredoc-style stdin we use, so the
     * console panel shows error messages inline with successful rows.
     */
    public function executeSqliteSql(ServerDatabase $db, string $sql, int $timeout = 60): string
    {
        if ($db->engine !== 'sqlite') {
            throw new \InvalidArgumentException('executeSqliteSql called on a non-sqlite database.');
        }

        $sql = trim($sql);
        if ($sql === '') {
            throw new \InvalidArgumentException('SQL statement is empty.');
        }

        $maxBytes = (int) config('server_database.import_max_bytes', 10485760);
        if (strlen($sql) > $maxBytes) {
            throw new \InvalidArgumentException('SQL statement exceeds the configured size limit.');
        }

        $server = $db->server;
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $path = $this->safeSqlitePath($db);

        [$out] = $this->remoteExec->sqliteExec($server, $path, $sql, $timeout);

        return trim($out);
    }

    /**
     * Public accessor for SSH backup/export paths that must stay jailed.
     */
    public function resolvedSqlitePath(ServerDatabase $db): string
    {
        return $this->safeSqlitePath($db);
    }

    /**
     * Resolve and sanity-check the SQLite file path on the host.
     * The path is stored in `server_databases.host` (repurposed for
     * SQLite — no host:port concept). We require it to sit under the
     * configured root (default `/var/lib/dply/sqlite`) so a typo or
     * tampered row can never wipe paths like `/etc/shadow`.
     */
    private function safeSqlitePath(ServerDatabase $db): string
    {
        $primaryRoot = '/'.trim((string) config('server_database.sqlite_root', '/var/lib/dply/sqlite'), '/');

        /** @var list<string> $extra */
        $extra = (array) config('server_database.sqlite_extra_safe_roots', []);
        $allowedRoots = array_values(array_unique(array_merge(
            [$primaryRoot],
            array_map(fn (string $r): string => '/'.trim($r, '/'), array_filter($extra, 'is_string'))
        )));

        $rawHost = trim((string) $db->host);
        $candidate = $rawHost !== '' ? '/'.ltrim($rawHost, '/') : $primaryRoot.'/'.$db->name.'.db';

        // Resolve `..` segments in-process so a stored path of
        // `/var/lib/dply/sqlite/../../etc/shadow` never escapes.
        $segments = [];
        foreach (explode('/', $candidate) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($segments);

                continue;
            }
            $segments[] = $part;
        }
        $resolved = '/'.implode('/', $segments);

        $insideAllowed = false;
        foreach ($allowedRoots as $root) {
            if ($resolved === $root || str_starts_with($resolved, $root.'/')) {
                $insideAllowed = true;
                break;
            }
        }

        if (! $insideAllowed) {
            throw new \InvalidArgumentException('SQLite path must sit under '.implode(' or ', $allowedRoots).'.');
        }

        if (substr($resolved, -3) !== '.db' && substr($resolved, -7) !== '.sqlite') {
            $resolved .= '.db';
        }

        return $resolved;
    }

    /**
     * Owner of the on-disk SQLite file. Defaults to the deploy user
     * configured in server_provision.deploy_ssh_user — that's the user
     * whose web app actually opens the file, so it needs read+write.
     */
    private function resolveSqliteOwner(ServerDatabase $db): string
    {
        $configured = (string) config('server_provision.deploy_ssh_user', 'dply');
        if ($configured !== '' && preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $configured)) {
            return $configured;
        }

        return 'root';
    }

    private function sanitizeMysqlIdentifier(string $value, string $fallback): string
    {
        if ($value !== '' && preg_match('/^[a-zA-Z0-9_]+$/', $value)) {
            return $value;
        }

        return $fallback;
    }

    /**
     * Postgres identifiers (database/role names) accept the same
     * [a-zA-Z0-9_] subset for unquoted use. We don't quote here because
     * upstream callers pass user-controlled values that have already
     * been validated by the Livewire form regex; the fallback covers
     * the "validation skipped somehow" case so we never inject random
     * SQL into a CREATE USER call.
     */
    private function sanitizePostgresIdentifier(string $value, string $fallback): string
    {
        if ($value !== '' && preg_match('/^[a-zA-Z0-9_]+$/', $value)) {
            return $value;
        }

        return $fallback;
    }

    private function sanitizeMongoIdentifier(string $value): string
    {
        if ($value !== '' && preg_match('/^[a-zA-Z0-9_]+$/', $value)) {
            return $value;
        }

        throw new \InvalidArgumentException('Invalid MongoDB identifier.');
    }

    private function sanitizeClickhouseIdentifier(string $value): string
    {
        if ($value !== '' && preg_match('/^[a-zA-Z0-9_]+$/', $value)) {
            return $value;
        }

        throw new \InvalidArgumentException('Invalid ClickHouse identifier.');
    }
}
