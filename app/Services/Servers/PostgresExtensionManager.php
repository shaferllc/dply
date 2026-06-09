<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Support\Servers\DatabaseEngineInstallScripts;
use App\Support\Servers\PostgresExtensionCatalog;
use Illuminate\Support\Str;

class PostgresExtensionManager
{
    public function __construct(
        protected ServerDatabaseRemoteExec $remoteExec,
    ) {}

    /**
     * @return list<string> Extension names installed in the postgres database (e.g. postgis, vector).
     */
    public function listInstalled(Server $server): array
    {
        [$out, $exit] = $this->remoteExec->postgresTuples(
            $server,
            "SELECT extname FROM pg_extension WHERE extname NOT IN ('plpgsql') ORDER BY extname",
            60,
        );

        if ($exit !== null && $exit !== 0) {
            throw new \RuntimeException('Could not list PostgreSQL extensions: '.Str::limit(trim($out), 400));
        }

        $names = [];
        foreach (preg_split("/\r\n|\n|\r/", trim($out)) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $names[] = $line;
            }
        }

        return array_values(array_unique($names));
    }

    public function install(Server $server, string $key): string
    {
        $meta = PostgresExtensionCatalog::for($key);
        $extension = $meta['extension'];
        $packages = implode(' ', array_map('escapeshellarg', $meta['packages']));

        $repo = $meta['requires_repo']
            ? DatabaseEngineInstallScripts::timescaledbRepoBootstrapScript()."\n"
            : '';

        [$pgVerOut] = $this->remoteExec->shellRunWithExit($server, 'ls /etc/postgresql 2>/dev/null | sort -rn | head -1', 15);
        $pgVer = trim($pgVerOut);
        $versionedPackages = '';
        if ($pgVer !== '' && preg_match('/^\d+$/', $pgVer)) {
            $versionedPackages = match ($key) {
                'postgis' => "apt-get install -y postgresql-{$pgVer}-postgis-3 2>/dev/null || ",
                'pgvector' => "apt-get install -y postgresql-{$pgVer}-pgvector 2>/dev/null || ",
                default => '',
            };
        }

        $script = $repo.
            "export DEBIAN_FRONTEND=noninteractive\n".
            "apt-get update -y\n".
            $versionedPackages.
            "apt-get install -y {$packages} 2>/dev/null || true\n".
            'sudo -u postgres psql -v ON_ERROR_STOP=1 -c '.escapeshellarg("CREATE EXTENSION IF NOT EXISTS {$extension};").' 2>&1';

        [$out, $exit] = $this->remoteExec->shellRunWithExit($server, $script, 300);
        $out = trim($out);
        if ($exit !== null && $exit !== 0) {
            throw new \RuntimeException(Str::limit($out, 800));
        }
        if (str_contains(strtolower($out), 'error') && ! str_contains(strtolower($out), 'already exists')) {
            throw new \RuntimeException(Str::limit($out, 800));
        }

        return $out;
    }
}
