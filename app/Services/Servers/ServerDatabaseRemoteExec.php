<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\ServerDatabaseAdminCredential;
use Illuminate\Support\Str;

class ServerDatabaseRemoteExec
{
    /**
     * Per-instance memo. The probes (probeMysql + probePostgres) and the
     * mysql/postgres command builders all call this method on the same
     * RemoteExec instance during a single render, leading to two-plus
     * duplicate selects against `server_database_admin_credentials`.
     *
     * Keyed by server id so a single RemoteExec used across two servers
     * (rare, but possible in batch jobs) still loads each independently.
     * The value is a tuple `[loaded?, model|null]` so a memoized "no
     * credential exists" result short-circuits subsequent calls.
     *
     * @var array<string, array{0: bool, 1: ?ServerDatabaseAdminCredential}>
     */
    protected array $adminCredentialMemo = [];

    public function adminCredential(Server $server): ?ServerDatabaseAdminCredential
    {
        $key = (string) $server->id;
        if (isset($this->adminCredentialMemo[$key])) {
            return $this->adminCredentialMemo[$key][1];
        }

        $cred = ServerDatabaseAdminCredential::query()->where('server_id', $server->id)->first();
        $this->adminCredentialMemo[$key] = [true, $cred];

        return $cred;
    }

    /**
     * Probe all six database engines in a SINGLE SSH round-trip.
     *
     * The per-engine helpers (probeMysql/probePostgres/…) each open their own
     * SSH connection — and multiplexing is off by default — so the original
     * {@see ServerDatabaseHostCapabilities::probe()} fan-out cost 6–9 sequential
     * handshakes on the workspace render path. This collapses every check into
     * one bash script run once, preserving each helper's detection semantics:
     *   - mysql/mariadb: dpkg server package installed + protocol connectivity
     *     (passwordless root, else stored root password)
     *   - postgres: sudo -u postgres (default) or direct 127.0.0.1 login
     *   - sqlite: sqlite3 binary present
     *   - mongodb: mongosh ping (authenticated when a stored password exists)
     *   - clickhouse: clickhouse-client SELECT 1 (optional --password)
     *
     * @return array{mysql: bool, mariadb: bool, postgres: bool, mongodb: bool, clickhouse: bool, sqlite: bool}
     */
    public function probeAllCapabilities(Server $server): array
    {
        $false = [
            'mysql' => false, 'mariadb' => false, 'postgres' => false,
            'mongodb' => false, 'clickhouse' => false, 'sqlite' => false,
        ];

        if (! $server->isReady() || empty($server->ssh_private_key)) {
            return $false;
        }

        $cred = $this->adminCredential($server);

        $mysqlUser = $cred?->mysql_root_username ?: 'root';
        $mysqlPw = (string) ($cred?->mysql_root_password ?? '');
        $pgUser = $cred?->postgres_superuser ?: 'postgres';
        $pgPw = (string) ($cred?->postgres_password ?? '');
        $mongoUser = $cred?->mongodb_admin_username ?: 'admin';
        $mongoPw = (string) ($cred?->mongodb_admin_password ?? '');
        $chUser = $cred?->clickhouse_admin_username ?: 'default';
        $chPw = (string) ($cred?->clickhouse_admin_password ?? '');

        // Postgres: sudo path when no stored superuser password (or explicitly
        // configured for sudo); direct 127.0.0.1 login otherwise — mirrors probePostgres().
        if (! $cred || $cred->postgres_use_sudo) {
            $pgLine = 'command -v psql >/dev/null 2>&1 && sudo -u postgres psql -c "SELECT 1" >/dev/null 2>&1 && POSTGRES=1';
        } else {
            $pgEnv = $pgPw !== '' ? 'env PGPASSWORD="$PG_PW" ' : '';
            $pgLine = 'command -v psql >/dev/null 2>&1 && '.$pgEnv.'psql -h 127.0.0.1 -U "$PG_USER" -c "SELECT 1" >/dev/null 2>&1 && POSTGRES=1';
        }

        // Mongo: authenticated ping when a stored admin password exists — mirrors probeMongodb().
        $mongoPing = escapeshellarg('db.adminCommand({ping:1})');
        if ($cred && $cred->mongodb_admin_password) {
            $mongoLine = 'command -v mongosh >/dev/null 2>&1 && systemctl is-active --quiet mongod && '
                .'mongosh -u "$MONGO_USER" -p "$MONGO_PW" --authenticationDatabase admin --quiet --eval '.$mongoPing.' >/dev/null 2>&1 && MONGODB=1';
        } else {
            $mongoLine = 'command -v mongosh >/dev/null 2>&1 && systemctl is-active --quiet mongod && '
                .'mongosh --quiet --eval '.$mongoPing.' >/dev/null 2>&1 && MONGODB=1';
        }

        // ClickHouse: SELECT 1 with optional --password — mirrors probeClickhouse().
        $chAuth = $chPw !== ''
            ? 'clickhouse-client --user "$CH_USER" --password "$CH_PW"'
            : 'clickhouse-client --user "$CH_USER"';
        $chLine = 'command -v clickhouse-client >/dev/null 2>&1 && '.$chAuth.' --query "SELECT 1" >/dev/null 2>&1 && CLICKHOUSE=1';

        $assignments = implode("\n", [
            'MYSQL_ROOT_USER='.escapeshellarg($mysqlUser),
            'MYSQL_ROOT_PW='.escapeshellarg($mysqlPw),
            'PG_USER='.escapeshellarg($pgUser),
            'PG_PW='.escapeshellarg($pgPw),
            'MONGO_USER='.escapeshellarg($mongoUser),
            'MONGO_PW='.escapeshellarg($mongoPw),
            'CH_USER='.escapeshellarg($chUser),
            'CH_PW='.escapeshellarg($chPw),
        ]);

        $script = <<<BASH
        set +e
        {$assignments}

        mysql_conn() {
          command -v mysql >/dev/null 2>&1 || return 1
          mysql -u root -e "SELECT 1" >/dev/null 2>&1 && return 0
          if [ -n "\$MYSQL_ROOT_PW" ]; then
            env MYSQL_PWD="\$MYSQL_ROOT_PW" mysql -u "\$MYSQL_ROOT_USER" -e "SELECT 1" >/dev/null 2>&1 && return 0
          fi
          return 1
        }

        MYSQL=0; MARIADB=0; POSTGRES=0; SQLITE=0; MONGODB=0; CLICKHOUSE=0

        dpkg-query -W -f='\${Status}' mysql-server 2>/dev/null | grep -q 'install ok installed' && mysql_conn && MYSQL=1
        dpkg-query -W -f='\${Status}' mariadb-server 2>/dev/null | grep -q 'install ok installed' && mysql_conn && MARIADB=1
        {$pgLine}
        command -v sqlite3 >/dev/null 2>&1 && SQLITE=1
        {$mongoLine}
        {$chLine}

        echo "DPLY_CAPS mysql=\$MYSQL mariadb=\$MARIADB postgres=\$POSTGRES sqlite=\$SQLITE mongodb=\$MONGODB clickhouse=\$CLICKHOUSE"
        BASH;

        try {
            $out = $this->execWithCandidates($server, 'bash -lc '.escapeshellarg($script), 60);
        } catch (\Throwable) {
            return $false;
        }

        if (! preg_match('/DPLY_CAPS\s+([^\n]*)/', $out, $m)) {
            return $false;
        }

        $caps = $false;
        foreach (array_keys($caps) as $engine) {
            if (preg_match('/\b'.$engine.'=([01])\b/', $m[1], $mm)) {
                $caps[$engine] = $mm[1] === '1';
            }
        }

        return $caps;
    }

    public function probeMysql(Server $server): bool
    {
        if (! $this->serverHasMysqlServerPackage($server)) {
            return false;
        }

        return $this->probeMysqlProtocolConnectivity($server);
    }

    public function probeMariadb(Server $server): bool
    {
        if (! $this->serverHasMariadbServerPackage($server)) {
            return false;
        }

        return $this->probeMysqlProtocolConnectivity($server);
    }

    protected function serverHasMysqlServerPackage(Server $server): bool
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            return false;
        }

        $script = <<<'BASH'
dpkg-query -W -f='${Status}' mysql-server 2>/dev/null | grep -q 'install ok installed' && echo YES
BASH;

        return str_contains(
            trim($this->execWithCandidates($server, 'bash -lc '.escapeshellarg($script), 20)),
            'YES',
        );
    }

    protected function serverHasMariadbServerPackage(Server $server): bool
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            return false;
        }

        $script = <<<'BASH'
dpkg-query -W -f='${Status}' mariadb-server 2>/dev/null | grep -q 'install ok installed' && echo YES
BASH;

        return str_contains(
            trim($this->execWithCandidates($server, 'bash -lc '.escapeshellarg($script), 20)),
            'YES',
        );
    }

    protected function probeMysqlProtocolConnectivity(Server $server): bool
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            return false;
        }

        $script = <<<'BASH'
set +e
if ! command -v mysql >/dev/null 2>&1; then echo NOCLIENT; exit 0; fi
if mysql -u root -e "SELECT 1" >/dev/null 2>&1; then echo PWLESS; exit 0; fi
echo FAIL
BASH;
        $out = trim($this->execWithCandidates($server, 'bash -lc '.escapeshellarg($script), 45));
        if (str_contains($out, 'PWLESS')) {
            return true;
        }

        $cred = $this->adminCredential($server);
        if ($cred && $cred->mysql_root_password) {
            $user = $cred->mysql_root_username ?: 'root';
            $cmd = 'env MYSQL_PWD='.escapeshellarg($cred->mysql_root_password).' mysql -u '.escapeshellarg($user).' -e "SELECT 1" >/dev/null 2>&1 && echo STORED';
            $out2 = trim($this->execWithCandidates($server, 'bash -lc '.escapeshellarg($cmd), 45));

            return str_contains($out2, 'STORED');
        }

        return false;
    }

    public function probePostgres(Server $server): bool
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            return false;
        }

        $cred = $this->adminCredential($server);

        if (! $cred || $cred->postgres_use_sudo) {
            $out = trim($this->execWithCandidates($server, 'bash -lc '.escapeshellarg('command -v psql >/dev/null && sudo -u postgres psql -c "SELECT 1" >/dev/null 2>&1 && echo OK'), 45));

            return str_contains($out, 'OK');
        }

        $user = $cred->postgres_superuser ?: 'postgres';
        $env = $cred->postgres_password
            ? 'env PGPASSWORD='.escapeshellarg($cred->postgres_password).' '
            : '';
        $cmd = $env.'psql -h 127.0.0.1 -U '.escapeshellarg($user).' -c "SELECT 1" >/dev/null 2>&1 && echo OK';
        $out = trim($this->execWithCandidates($server, 'bash -lc '.escapeshellarg($cmd), 45));

        return str_contains($out, 'OK');
    }

    public function probeSqlite(Server $server): bool
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            return false;
        }

        $out = trim($this->execWithCandidates($server, 'bash -lc '.escapeshellarg('command -v sqlite3 >/dev/null && echo OK'), 30));

        return str_contains($out, 'OK');
    }

    public function probeMongodb(Server $server): bool
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            return false;
        }

        $cred = $this->adminCredential($server);
        $ping = 'db.adminCommand({ping:1})';
        if ($cred && $cred->mongodb_admin_password) {
            $user = $cred->mongodb_admin_username ?: 'admin';
            $inner = 'command -v mongosh >/dev/null && systemctl is-active --quiet mongod && '.
                'mongosh -u '.escapeshellarg($user).
                ' -p '.escapeshellarg($cred->mongodb_admin_password).
                ' --authenticationDatabase admin --quiet --eval '.escapeshellarg($ping).
                ' >/dev/null 2>&1 && echo OK';
        } else {
            $inner = 'command -v mongosh >/dev/null && systemctl is-active --quiet mongod && '.
                'mongosh --quiet --eval '.escapeshellarg($ping).' >/dev/null 2>&1 && echo OK';
        }

        $out = trim($this->execWithCandidates($server, 'bash -lc '.escapeshellarg($inner), 45));

        return str_contains($out, 'OK');
    }

    public function probeClickhouse(Server $server): bool
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            return false;
        }

        $cred = $this->adminCredential($server);
        $user = $cred?->clickhouse_admin_username ?: 'default';
        $pass = $cred?->clickhouse_admin_password ?? '';
        $auth = $pass !== ''
            ? 'clickhouse-client --user '.escapeshellarg($user).' --password '.escapeshellarg($pass)
            : 'clickhouse-client --user '.escapeshellarg($user);
        $cmd = $auth.' --query "SELECT 1" >/dev/null 2>&1 && echo OK';
        $out = trim($this->execWithCandidates($server, 'bash -lc '.escapeshellarg($cmd), 45));

        return str_contains($out, 'OK');
    }

    /**
     * @return array{0: string, 1: int|null}
     */
    public function mongoshRunWithExit(Server $server, string $javascript, int $timeout = 120): array
    {
        $cred = $this->adminCredential($server);
        $prefix = 'mongosh --quiet';
        if ($cred && $cred->mongodb_admin_password) {
            $user = $cred->mongodb_admin_username ?: 'admin';
            $prefix = 'mongosh --quiet -u '.escapeshellarg($user).
                ' -p '.escapeshellarg($cred->mongodb_admin_password).
                ' --authenticationDatabase admin';
        }
        $inner = $prefix.' --eval '.escapeshellarg($javascript).' 2>&1';

        return $this->execWithCandidatesAndExitCode($server, 'bash -lc '.escapeshellarg($inner), $timeout);
    }

    /**
     * @return array{0: string, 1: int|null}
     */
    public function clickhouseRunWithExit(Server $server, string $sql, int $timeout = 120): array
    {
        $cred = $this->adminCredential($server);
        $user = $cred?->clickhouse_admin_username ?: 'default';
        $pass = $cred?->clickhouse_admin_password ?? '';
        $auth = $pass !== ''
            ? 'clickhouse-client --user '.escapeshellarg($user).' --password '.escapeshellarg($pass)
            : 'clickhouse-client --user '.escapeshellarg($user);
        $inner = $auth.' --multiquery --query '.escapeshellarg($sql).' 2>&1';

        return $this->execWithCandidatesAndExitCode($server, 'bash -lc '.escapeshellarg($inner), $timeout);
    }

    public function mongodump(Server $server, string $database, string $username, string $password, int $timeout = 600): string
    {
        $inner = 'mongodump --db '.escapeshellarg($database).
            ' --username '.escapeshellarg($username).
            ' --password '.escapeshellarg($password).
            ' --authenticationDatabase '.escapeshellarg($database).
            ' --archive 2>&1';

        return $this->execWithCandidates($server, 'bash -lc '.escapeshellarg($inner), $timeout);
    }

    public function mysqlExecute(Server $server, string $sql, int $timeout = 120): string
    {
        $cred = $this->adminCredential($server);
        $user = $cred?->mysql_root_username ?: 'root';
        $prefix = '';
        if ($cred && $cred->mysql_root_password) {
            $prefix = 'env MYSQL_PWD='.escapeshellarg($cred->mysql_root_password).' ';
        }

        $inner = $prefix.'mysql -u '.escapeshellarg($user).' -N -e '.escapeshellarg($sql).' 2>&1';

        return $this->execWithCandidates($server, 'bash -lc '.escapeshellarg($inner), $timeout);
    }

    public function mysqlExecExitCode(Server $server): ?int
    {
        return null;
    }

    /**
     * Run a one-off mysql command (used after exec to read exit status from same connection — caller must use one SSH exec chain).
     * Prefer {@see mysqlExecute} which runs a single remote bash -lc.
     */
    public function mysqlRunWithExit(Server $server, string $sql, int $timeout = 120): array
    {
        $cred = $this->adminCredential($server);
        $user = $cred?->mysql_root_username ?: 'root';
        $prefix = '';
        if ($cred && $cred->mysql_root_password) {
            $prefix = 'env MYSQL_PWD='.escapeshellarg($cred->mysql_root_password).' ';
        }
        $inner = $prefix.'mysql -u '.escapeshellarg($user).' -N -e '.escapeshellarg($sql).' 2>&1';

        return $this->execWithCandidatesAndExitCode($server, 'bash -lc '.escapeshellarg($inner), $timeout);
    }

    public function postgresRun(Server $server, string $sql, int $timeout = 120): array
    {
        $cred = $this->adminCredential($server);
        $inner = $this->postgresBashFragment($sql, $cred, tuples: false);

        return $this->execWithCandidatesAndExitCode($server, 'bash -lc '.escapeshellarg($inner), $timeout);
    }

    /**
     * Run a query returning rows (no headers), e.g. listing database names.
     *
     * @return array{0: string, 1: int|null}
     */
    public function postgresTuples(Server $server, string $sql, int $timeout = 120): array
    {
        $cred = $this->adminCredential($server);
        $inner = $this->postgresBashFragment($sql, $cred, tuples: true);

        return $this->execWithCandidatesAndExitCode($server, 'bash -lc '.escapeshellarg($inner), $timeout);
    }

    private function postgresBashFragment(string $sql, ?ServerDatabaseAdminCredential $cred, bool $tuples): string
    {
        $flags = $tuples
            ? '-t -A -v ON_ERROR_STOP=1'
            : '-v ON_ERROR_STOP=0';

        if (! $cred || $cred->postgres_use_sudo) {
            return 'sudo -u postgres psql '.$flags.' -c '.escapeshellarg($sql).' 2>&1';
        }

        $user = $cred->postgres_superuser ?: 'postgres';
        $env = $cred->postgres_password
            ? 'env PGPASSWORD='.escapeshellarg($cred->postgres_password).' '
            : '';

        return $env.'psql -h 127.0.0.1 -U '.escapeshellarg($user).' '.$flags.' -c '.escapeshellarg($sql).' 2>&1';
    }

    /**
     * Stream mysqldump to stdout over SSH (returns remote command output).
     */
    public function mysqldump(Server $server, string $database, string $username, string $password, int $timeout = 600): string
    {
        $inner = 'env MYSQL_PWD='.escapeshellarg($password).' mysqldump -u '.escapeshellarg($username)
            .' --single-transaction --quick --routines=false '.escapeshellarg($database).' 2>&1';

        return $this->execWithCandidates($server, 'bash -lc '.escapeshellarg($inner), $timeout);
    }

    /**
     * Stream pg_dump over SSH.
     */
    public function pgDump(Server $server, string $database, string $username, string $password, int $timeout = 600): string
    {
        $inner = 'env PGPASSWORD='.escapeshellarg($password).' pg_dump -h 127.0.0.1 -U '.escapeshellarg($username)
            .' '.escapeshellarg($database).' 2>&1';

        return $this->execWithCandidates($server, 'bash -lc '.escapeshellarg($inner), $timeout);
    }

    /**
     * Import SQL into MySQL database using application credentials (pipes base64-decoded SQL).
     */
    public function mysqlImportFromString(Server $server, string $database, string $username, string $password, string $sql, int $timeout = 600, ?int $maxBytes = null): string
    {
        $max = $maxBytes ?? (int) config('server_database.import_max_bytes', 10485760);
        if (strlen($sql) > $max) {
            throw new \InvalidArgumentException('SQL import exceeds maximum allowed size.');
        }

        $b64 = base64_encode($sql);
        $inner = 'echo '.escapeshellarg($b64).' | base64 -d | env MYSQL_PWD='.escapeshellarg($password)
            .' mysql -u '.escapeshellarg($username).' '.escapeshellarg($database).' 2>&1';

        return $this->execWithCandidates($server, 'bash -lc '.escapeshellarg($inner), $timeout);
    }

    public function postgresImportFromString(Server $server, string $database, string $username, string $password, string $sql, int $timeout = 600, ?int $maxBytes = null): string
    {
        $max = $maxBytes ?? (int) config('server_database.import_max_bytes', 10485760);
        if (strlen($sql) > $max) {
            throw new \InvalidArgumentException('SQL import exceeds maximum allowed size.');
        }

        $b64 = base64_encode($sql);
        $inner = 'echo '.escapeshellarg($b64).' | base64 -d | env PGPASSWORD='.escapeshellarg($password)
            .' psql -h 127.0.0.1 -U '.escapeshellarg($username).' -d '.escapeshellarg($database).' 2>&1';

        return $this->execWithCandidates($server, 'bash -lc '.escapeshellarg($inner), $timeout);
    }

    /**
     * Run a raw bash command and return [stdout+stderr, exit_code].
     * Public wrapper so engine-specific provisioners can run filesystem-style
     * commands (sqlite touch / rm, etc.) without re-implementing the
     * root-ssh-with-deploy-fallback selection logic.
     *
     * @return array{0: string, 1: int|null}
     */
    public function shellRunWithExit(Server $server, string $command, int $timeout = 60): array
    {
        return $this->execWithCandidatesAndExitCode($server, 'bash -lc '.escapeshellarg($command), $timeout);
    }

    /**
     * Run arbitrary SQL against a SQLite file via the `sqlite3` binary.
     * SQL is base64-piped through stdin so embedded single quotes,
     * double quotes, semicolons, and newlines all survive the SSH +
     * shell layers without escaping gymnastics.
     *
     * `-header -column` makes SELECT output readable in the workspace's
     * console pane without us doing extra formatting.
     *
     * @return array{0: string, 1: int|null}
     */
    public function sqliteExec(Server $server, string $path, string $sql, int $timeout = 60): array
    {
        $b64 = base64_encode($sql);
        $cmd = 'echo '.escapeshellarg($b64).' | base64 -d | sqlite3 -header -column '.escapeshellarg($path).' 2>&1';

        return $this->shellRunWithExit($server, $cmd, $timeout);
    }

    /**
     * Write a consistent SQLite snapshot to a path on the server (no bytes cross SSH).
     *
     * @throws \RuntimeException on non-zero remote exit or oversize file
     */
    public function sqliteBackupToPath(Server $server, string $sourcePath, string $destPath, int $maxBytes, int $timeout = 300): int
    {
        $dir = dirname($destPath);
        $script = 'set -e; '.
            'mkdir -p '.escapeshellarg($dir).' && '.
            'sqlite3 '.escapeshellarg($sourcePath).' ".backup '.escapeshellarg($destPath).'" >/dev/null && '.
            'stat -c%s '.escapeshellarg($destPath);

        [$out, $exit] = $this->shellRunWithExit($server, 'bash -lc '.escapeshellarg($script), $timeout);

        if ($exit !== null && $exit !== 0) {
            throw new \RuntimeException(Str::limit(trim($out), 800));
        }

        $bytes = (int) trim($out);
        if ($bytes <= 0) {
            throw new \RuntimeException('SQLite backup produced an empty file.');
        }

        if ($bytes > $maxBytes) {
            $this->shellRunWithExit($server, 'bash -lc '.escapeshellarg('rm -f '.escapeshellarg($destPath)), 30);

            throw new \RuntimeException(sprintf(
                'SQLite backup exceeds the %d byte cap; raise SERVER_DATABASE_SQLITE_BACKUP_MAX_BYTES if needed.',
                $maxBytes
            ));
        }

        return $bytes;
    }

    public function mysqldumpToPath(Server $server, string $database, string $username, string $password, string $destPath, int $timeout = 600): int
    {
        $dir = dirname($destPath);
        $inner = 'mkdir -p '.escapeshellarg($dir).' && '.
            'env MYSQL_PWD='.escapeshellarg($password).' mysqldump -u '.escapeshellarg($username).
            ' --single-transaction --quick --routines=false '.escapeshellarg($database).
            ' > '.escapeshellarg($destPath).' 2>&1 && stat -c%s '.escapeshellarg($destPath);

        [$out, $exit] = $this->shellRunWithExit($server, 'bash -lc '.escapeshellarg($inner), $timeout);

        if ($exit !== null && $exit !== 0) {
            throw new \RuntimeException(Str::limit(trim($out), 800));
        }

        return max(0, (int) trim($out));
    }

    public function pgDumpToPath(Server $server, string $database, string $username, string $password, string $destPath, int $timeout = 600): int
    {
        $dir = dirname($destPath);
        $inner = 'mkdir -p '.escapeshellarg($dir).' && '.
            'env PGPASSWORD='.escapeshellarg($password).' pg_dump -h 127.0.0.1 -U '.escapeshellarg($username).
            ' '.escapeshellarg($database).
            ' > '.escapeshellarg($destPath).' 2>&1 && stat -c%s '.escapeshellarg($destPath);

        [$out, $exit] = $this->shellRunWithExit($server, 'bash -lc '.escapeshellarg($inner), $timeout);

        if ($exit !== null && $exit !== 0) {
            throw new \RuntimeException(Str::limit(trim($out), 800));
        }

        return max(0, (int) trim($out));
    }

    /**
     * Delete oldest files under a server backup tree until total size is at or below $maxBytes.
     */
    public function pruneRemoteBackupTree(Server $server, string $serverTreeRoot, int $maxBytes, int $timeout = 120): void
    {
        if ($maxBytes <= 0) {
            return;
        }

        $inner = 'ROOT='.escapeshellarg($serverTreeRoot).'; '.
            'MAX='.escapeshellarg((string) $maxBytes).'; '.
            'if [ ! -d "$ROOT" ]; then exit 0; fi; '.
            'total() { find "$ROOT" -type f -printf \'%s\\n\' 2>/dev/null | awk \'{s+=$1} END {print s+0}\'; }; '.
            'while [ "$(total)" -gt "$MAX" ]; do '.
            'oldest="$(find "$ROOT" -type f -printf \'%T@ %p\\n\' 2>/dev/null | sort -n | head -1 | cut -d\' \' -f2-)"; '.
            'if [ -z "$oldest" ] || [ ! -f "$oldest" ]; then break; fi; '.
            'rm -f "$oldest"; '.
            'done';

        $this->shellRunWithExit($server, 'bash -lc '.escapeshellarg($inner), $timeout);
    }

    /**
     * Snapshot a SQLite database via the online backup API and stream the result back over SSH.
     * Uses `sqlite3 source.db ".backup tmp.db"` so concurrent writers see a consistent file.
     * The temp file on the remote is removed on every shell exit path via `trap`.
     *
     * @throws \RuntimeException on non-zero remote exit, an empty result, or a payload above $maxBytes
     */
    public function sqliteBackup(Server $server, string $sourcePath, int $maxBytes, int $timeout = 300): string
    {
        $tmp = '/tmp/dply-sqlite-backup-'.Str::ulid().'.db';
        $script = 'set -e; '.
            'trap "rm -f '.escapeshellarg($tmp).'" EXIT; '.
            'sqlite3 '.escapeshellarg($sourcePath).' ".backup '.escapeshellarg($tmp).'" >/dev/null; '.
            'base64 -w0 '.escapeshellarg($tmp);

        [$out, $exit] = $this->execWithCandidatesAndExitCode($server, 'bash -lc '.escapeshellarg($script), $timeout);

        if ($exit !== null && $exit !== 0) {
            throw new \RuntimeException(Str::limit(trim($out), 800));
        }

        $b64 = trim($out);
        if ($b64 === '') {
            throw new \RuntimeException('SQLite backup produced no output.');
        }

        // Cheap pre-check before decoding: base64 inflates by ~33%, so encoded length > maxBytes*1.34 means
        // the underlying file is definitely over the cap. Saves us from decoding hundreds of megabytes only to throw.
        if (strlen($b64) > (int) ceil($maxBytes * 1.4)) {
            throw new \RuntimeException(sprintf(
                'SQLite backup exceeds the %d byte cap; raise SERVER_DATABASE_SQLITE_BACKUP_MAX_BYTES if needed.',
                $maxBytes
            ));
        }

        $decoded = base64_decode($b64, true);
        if ($decoded === false) {
            throw new \RuntimeException('SQLite backup output was not valid base64.');
        }

        if (strlen($decoded) > $maxBytes) {
            throw new \RuntimeException(sprintf(
                'SQLite backup exceeds the %d byte cap; raise SERVER_DATABASE_SQLITE_BACKUP_MAX_BYTES if needed.',
                $maxBytes
            ));
        }

        return $decoded;
    }

    protected function execWithCandidates(Server $server, string $command, int $timeout): string
    {
        return app(ServerSshConnectionRunner::class)->run(
            $server,
            fn ($ssh): string => $ssh->exec($command, $timeout),
            $this->useRootSsh(),
            $this->fallbackToDeployUserSsh()
        );
    }

    /**
     * @return array{0: string, 1: int|null}
     */
    protected function execWithCandidatesAndExitCode(Server $server, string $command, int $timeout): array
    {
        return app(ServerSshConnectionRunner::class)->run(
            $server,
            fn ($ssh): array => [$ssh->exec($command, $timeout), $ssh->lastExecExitCode()],
            $this->useRootSsh(),
            $this->fallbackToDeployUserSsh()
        );
    }

    protected function useRootSsh(): bool
    {
        return (bool) config('server_database.use_root_ssh', true);
    }

    protected function fallbackToDeployUserSsh(): bool
    {
        return (bool) config('server_database.fallback_to_deploy_user_ssh', true);
    }
}
