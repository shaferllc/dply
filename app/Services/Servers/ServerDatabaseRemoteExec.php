<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\ServerDatabaseAdminCredential;
use App\Services\SshConnection;

class ServerDatabaseRemoteExec
{
    public function adminCredential(Server $server): ?ServerDatabaseAdminCredential
    {
        return ServerDatabaseAdminCredential::query()->where('server_id', $server->id)->first();
    }

    public function probeMysql(Server $server): bool
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            return false;
        }

        $ssh = new SshConnection($server);
        $script = <<<'BASH'
set +e
if ! command -v mysql >/dev/null 2>&1; then echo NOCLIENT; exit 0; fi
if mysql -u root -e "SELECT 1" >/dev/null 2>&1; then echo PWLESS; exit 0; fi
echo FAIL
BASH;
        $out = trim($ssh->exec('bash -lc '.escapeshellarg($script), 45));
        if (str_contains($out, 'PWLESS')) {
            return true;
        }

        $cred = $this->adminCredential($server);
        if ($cred && $cred->mysql_root_password) {
            $user = $cred->mysql_root_username ?: 'root';
            $cmd = 'env MYSQL_PWD='.escapeshellarg($cred->mysql_root_password).' mysql -u '.escapeshellarg($user).' -e "SELECT 1" >/dev/null 2>&1 && echo STORED';
            $out2 = trim($ssh->exec('bash -lc '.escapeshellarg($cmd), 45));

            return str_contains($out2, 'STORED');
        }

        return false;
    }

    public function probePostgres(Server $server): bool
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            return false;
        }

        $ssh = new SshConnection($server);
        $cred = $this->adminCredential($server);

        if (! $cred || $cred->postgres_use_sudo) {
            $out = trim($ssh->exec('bash -lc '.escapeshellarg('command -v psql >/dev/null && sudo -u postgres psql -c "SELECT 1" >/dev/null 2>&1 && echo OK'), 45));

            return str_contains($out, 'OK');
        }

        $user = $cred->postgres_superuser ?: 'postgres';
        $env = $cred->postgres_password
            ? 'env PGPASSWORD='.escapeshellarg($cred->postgres_password).' '
            : '';
        $cmd = $env.'psql -h 127.0.0.1 -U '.escapeshellarg($user).' -c "SELECT 1" >/dev/null 2>&1 && echo OK';
        $out = trim($ssh->exec('bash -lc '.escapeshellarg($cmd), 45));

        return str_contains($out, 'OK');
    }

    public function probeRedis(Server $server): bool
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            return false;
        }

        $ssh = new SshConnection($server);
        $out = trim($ssh->exec('bash -lc '.escapeshellarg('command -v redis-cli >/dev/null && redis-cli ping 2>/dev/null'), 30));

        return strtoupper($out) === 'PONG';
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
        $ssh = new SshConnection($server);

        return $ssh->exec('bash -lc '.escapeshellarg($inner), $timeout);
    }

    public function mysqlExecExitCode(Server $server): ?int
    {
        $ssh = new SshConnection($server);

        return $ssh->lastExecExitCode();
    }

    /**
     * Run a one-off mysql command (used after exec to read exit status from same connection — caller must use one SSH exec chain).
     * Prefer {@see mysqlExecute} which runs a single remote bash -lc.
     */
    public function mysqlRunWithExit(Server $server, string $sql, int $timeout = 120): array
    {
        $ssh = new SshConnection($server);
        $cred = $this->adminCredential($server);
        $user = $cred?->mysql_root_username ?: 'root';
        $prefix = '';
        if ($cred && $cred->mysql_root_password) {
            $prefix = 'env MYSQL_PWD='.escapeshellarg($cred->mysql_root_password).' ';
        }
        $inner = $prefix.'mysql -u '.escapeshellarg($user).' -N -e '.escapeshellarg($sql).' 2>&1';
        $out = $ssh->exec('bash -lc '.escapeshellarg($inner), $timeout);
        $exit = $ssh->lastExecExitCode();

        return [$out, $exit];
    }

    public function postgresRun(Server $server, string $sql, int $timeout = 120): array
    {
        $ssh = new SshConnection($server);
        $cred = $this->adminCredential($server);
        $inner = $this->postgresBashFragment($sql, $cred, tuples: false);
        $out = $ssh->exec('bash -lc '.escapeshellarg($inner), $timeout);
        $exit = $ssh->lastExecExitCode();

        return [$out, $exit];
    }

    /**
     * Run a query returning rows (no headers), e.g. listing database names.
     *
     * @return array{0: string, 1: int|null}
     */
    public function postgresTuples(Server $server, string $sql, int $timeout = 120): array
    {
        $ssh = new SshConnection($server);
        $cred = $this->adminCredential($server);
        $inner = $this->postgresBashFragment($sql, $cred, tuples: true);
        $out = $ssh->exec('bash -lc '.escapeshellarg($inner), $timeout);
        $exit = $ssh->lastExecExitCode();

        return [$out, $exit];
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
        $ssh = new SshConnection($server);
        $inner = 'env MYSQL_PWD='.escapeshellarg($password).' mysqldump -u '.escapeshellarg($username)
            .' --single-transaction --quick --routines=false '.escapeshellarg($database).' 2>&1';

        return $ssh->exec('bash -lc '.escapeshellarg($inner), $timeout);
    }

    /**
     * Stream pg_dump over SSH.
     */
    public function pgDump(Server $server, string $database, string $username, string $password, int $timeout = 600): string
    {
        $ssh = new SshConnection($server);
        $inner = 'env PGPASSWORD='.escapeshellarg($password).' pg_dump -h 127.0.0.1 -U '.escapeshellarg($username)
            .' '.escapeshellarg($database).' 2>&1';

        return $ssh->exec('bash -lc '.escapeshellarg($inner), $timeout);
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

        $ssh = new SshConnection($server);
        $b64 = base64_encode($sql);
        $inner = 'echo '.escapeshellarg($b64).' | base64 -d | env MYSQL_PWD='.escapeshellarg($password)
            .' mysql -u '.escapeshellarg($username).' '.escapeshellarg($database).' 2>&1';

        return $ssh->exec('bash -lc '.escapeshellarg($inner), $timeout);
    }

    public function postgresImportFromString(Server $server, string $database, string $username, string $password, string $sql, int $timeout = 600, ?int $maxBytes = null): string
    {
        $max = $maxBytes ?? (int) config('server_database.import_max_bytes', 10485760);
        if (strlen($sql) > $max) {
            throw new \InvalidArgumentException('SQL import exceeds maximum allowed size.');
        }

        $ssh = new SshConnection($server);
        $b64 = base64_encode($sql);
        $inner = 'echo '.escapeshellarg($b64).' | base64 -d | env PGPASSWORD='.escapeshellarg($password)
            .' psql -h 127.0.0.1 -U '.escapeshellarg($username).' -d '.escapeshellarg($database).' 2>&1';

        return $ssh->exec('bash -lc '.escapeshellarg($inner), $timeout);
    }
}
