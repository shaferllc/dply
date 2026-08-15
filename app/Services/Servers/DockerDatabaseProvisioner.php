<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Modules\Database\Support\DockerDatabase;
use App\Support\Servers\DedicatedCacheServerProvisionConfig;
use App\Support\Servers\DedicatedDatabaseServerProvisionConfig;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Provisions a database engine inside a Docker container on a BYO server.
 */
final class DockerDatabaseProvisioner
{
    public function __construct(
        private readonly ExecuteRemoteTaskOnServer $remote,
    ) {}

    /**
     * @return array{container: string, host_port: int, output: string}
     */
    public function provision(
        Server $server,
        Site $site,
        ServerDatabase $db,
        bool $remoteAccess = false,
        string $allowedFrom = '',
    ): array {
        $engine = strtolower((string) $db->engine);
        if (! in_array($engine, DockerDatabase::supportedEngines(), true)) {
            throw new RuntimeException(__('This engine cannot be provisioned in Docker yet.'));
        }

        $container = $this->containerName($site, $db);
        $this->shellSafe((string) $db->name);
        $this->shellSafe((string) $db->username);
        $this->shellSafe((string) $db->password);

        $script = match ($engine) {
            'postgres' => $this->postgresScript($container, $site, $db, $remoteAccess, $allowedFrom),
            'redis' => $this->redisScript($container, $site, $db, $remoteAccess, $allowedFrom),
            default => $this->mysqlScript($container, $site, $db, $remoteAccess, $allowedFrom),
        };

        $output = $this->remote->runScript(
            $server,
            'provision_docker_database',
            $script,
            600,
            asRoot: true,
        );

        if (! $output->isSuccessful()) {
            throw new RuntimeException(Str::limit(trim($output->getBuffer()), 1200));
        }

        $text = trim($output->getBuffer());
        $containerName = $container;
        $hostPort = null;

        foreach (preg_split("/\r\n|\n|\r/", $text) ?: [] as $line) {
            if (str_starts_with($line, 'DPLY_CONTAINER=')) {
                $containerName = substr($line, strlen('DPLY_CONTAINER='));
            }
            if (str_starts_with($line, 'DPLY_HOST_PORT=')) {
                $hostPort = (int) substr($line, strlen('DPLY_HOST_PORT='));
            }
        }

        if ($hostPort === null || $hostPort <= 0) {
            throw new RuntimeException(__('Docker database started but dply could not read the host port.'));
        }

        return [
            'container' => $containerName,
            'host_port' => $hostPort,
            'output' => $text,
        ];
    }

    public function destroy(Server $server, string $container, string $volume): void
    {
        if (! $server->dockerEnginePresent()) {
            return;
        }

        $script = <<<BASH
CONTAINER={$this->bashQuote($container)}
VOLUME={$this->bashQuote($volume)}
docker rm -f "\$CONTAINER" >/dev/null 2>&1 || true
docker volume rm "\$VOLUME" >/dev/null 2>&1 || true
BASH;

        $this->remote->runScript($server, 'destroy_docker_database', $script, 120, asRoot: true);
    }

    private function mysqlScript(
        string $container,
        Site $site,
        ServerDatabase $db,
        bool $remoteAccess = false,
        string $allowedFrom = '',
    ): string {
        $image = DockerDatabase::imageForEngine('mysql');
        $portBase = DockerDatabase::hostPortBaseForEngine('mysql');
        $dbName = (string) $db->name;
        $user = (string) $db->username;
        $password = (string) $db->password;
        $rootPassword = Str::password(24, symbols: false);

        return $this->baseScript($container, $image, $portBase, 3306, '/var/lib/mysql', $remoteAccess, $allowedFrom, <<<BASH
      -e MYSQL_DATABASE={$this->bashQuote($dbName)} \\
      -e MYSQL_USER={$this->bashQuote($user)} \\
      -e MYSQL_PASSWORD={$this->bashQuote($password)} \\
      -e MYSQL_ROOT_PASSWORD={$this->bashQuote($rootPassword)} \\
BASH, <<<BASH
    if docker exec "\$CONTAINER" mysqladmin ping -h127.0.0.1 -u{$this->bashQuote($user)} -p{$this->bashQuote($password)} --silent >/dev/null 2>&1; then
      break
    fi
BASH);
    }

    private function postgresScript(
        string $container,
        Site $site,
        ServerDatabase $db,
        bool $remoteAccess = false,
        string $allowedFrom = '',
    ): string {
        $image = DockerDatabase::imageForEngine('postgres');
        $portBase = DockerDatabase::hostPortBaseForEngine('postgres');
        $dbName = (string) $db->name;
        $user = (string) $db->username;
        $password = (string) $db->password;

        return $this->baseScript($container, $image, $portBase, 5432, '/var/lib/postgresql/data', $remoteAccess, $allowedFrom, <<<BASH
      -e POSTGRES_DB={$this->bashQuote($dbName)} \\
      -e POSTGRES_USER={$this->bashQuote($user)} \\
      -e POSTGRES_PASSWORD={$this->bashQuote($password)} \\
BASH, <<<BASH
    if docker exec "\$CONTAINER" pg_isready -U {$this->bashQuote($user)} -d {$this->bashQuote($dbName)} >/dev/null 2>&1; then
      break
    fi
BASH);
    }

    private function redisScript(
        string $container,
        Site $site,
        ServerDatabase $db,
        bool $remoteAccess = false,
        string $allowedFrom = '',
    ): string {
        $image = DockerDatabase::imageForEngine('redis');
        $portBase = DockerDatabase::hostPortBaseForEngine('redis');
        $password = (string) $db->password;

        return $this->baseScript($container, $image, $portBase, 6379, '/data', $remoteAccess, $allowedFrom, '', <<<BASH
    if docker exec "\$CONTAINER" redis-cli -a {$this->bashQuote($password)} ping 2>/dev/null | grep -q PONG; then
      break
    fi
BASH, ' redis-server --requirepass '.$this->bashQuote($password));
    }

    private function baseScript(
        string $container,
        string $image,
        int $portBase,
        int $internalPort,
        string $volumeMount,
        bool $remoteAccess,
        string $allowedFrom,
        string $envFlags,
        string $readyCheck,
        string $runSuffix = '',
    ): string {
        $volume = 'dply-db-'.$container;
        $bindHost = $remoteAccess ? '0.0.0.0' : '127.0.0.1';
        $ufwLines = $this->ufwAllowLines($remoteAccess, $allowedFrom);

        return <<<BASH
#!/bin/bash
set -euo pipefail
CONTAINER={$this->bashQuote($container)}
IMAGE={$this->bashQuote($image)}
PORT_BASE={$portBase}
INTERNAL_PORT={$internalPort}
VOLUME={$this->bashQuote($volume)}
BIND_HOST={$this->bashQuote($bindHost)}

if ! command -v docker >/dev/null 2>&1; then
  echo "Docker is not installed on this server." >&2
  exit 1
fi

PORT="\$PORT_BASE"
while ss -ltn 2>/dev/null | awk '{print \$4}' | grep -q ":\$PORT\$"; do
  PORT=$((PORT + 1))
done

docker pull "\$IMAGE"
docker rm -f "\$CONTAINER" >/dev/null 2>&1 || true
docker volume create "\$VOLUME" >/dev/null 2>&1 || true

docker run -d --name "\$CONTAINER" \\
{$envFlags}      -p "\$BIND_HOST:\$PORT:\$INTERNAL_PORT" \\
      -v "\$VOLUME:{$volumeMount}" \\
      --restart unless-stopped \\
      "\$IMAGE"{$runSuffix}

{$ufwLines}

for i in $(seq 1 60); do
  if docker inspect -f '{{.State.Running}}' "\$CONTAINER" 2>/dev/null | grep -q true; then
{$readyCheck}
  fi
  sleep 2
done

echo "DPLY_CONTAINER=\$CONTAINER"
echo "DPLY_HOST_PORT=\$PORT"
BASH;
    }

    private function ufwAllowLines(bool $remoteAccess, string $allowedFrom): string
    {
        if (! $remoteAccess || ! DedicatedDatabaseServerProvisionConfig::isAllowedSourceCidr($allowedFrom)) {
            return '';
        }

        $lines = [];
        foreach (DedicatedCacheServerProvisionConfig::splitAllowedFrom($allowedFrom) as $cidr) {
            $lines[] = 'ufw allow from '.escapeshellarg($cidr).' to any port "\$PORT" proto tcp comment '.escapeshellarg('dply-docker-db');
        }

        return implode("\n", $lines);
    }

    private function containerName(Site $site, ServerDatabase $db): string
    {
        $slug = Str::slug((string) ($site->slug ?: $site->id), '-');
        $name = Str::slug((string) $db->name, '-');

        return Str::limit('dply-db-'.$slug.'-'.$name, 63, '');
    }

    private function shellSafe(string $value): string
    {
        if (preg_match('/^[a-zA-Z0-9_]+$/', $value) !== 1) {
            throw new RuntimeException(__('Database name and credentials must be alphanumeric/underscore for Docker provisioning.'));
        }

        return $value;
    }

    private function bashQuote(string $value): string
    {
        return escapeshellarg($value);
    }
}
