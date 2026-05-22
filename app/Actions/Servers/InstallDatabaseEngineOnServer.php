<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Contracts\RemoteShell;
use App\Models\Server;
use App\Models\ServerDatabaseEngine;
use App\Services\Servers\ServerProvisionCommandBuilder;
use App\Services\SshConnection;
use Closure;

/**
 * Install a database engine package on a server via SSH, then register
 * it in the server_database_engines table.
 *
 * Bridges {@see ServerProvisionCommandBuilder}'s install-engine shell
 * lines (the same ones the bootstrap script uses) with the
 * {@see AttachDatabaseEngineToServer} data update — so an operator
 * can add Postgres 17 to a MySQL-only server with one action call
 * instead of running apt by hand and then registering.
 *
 * Returns the captured SSH output so the dashboard / CLI can surface
 * apt's progress for slower installs (Postgres source-build via the
 * official apt repo can take 60–120s).
 *
 * Idempotent through both layers: the install lines wrap each apt
 * step with a `dpkg -s` guard (re-running is a no-op when the
 * package is already there), and the registration step is
 * idempotent against (server_id, engine).
 */
class InstallDatabaseEngineOnServer
{
    public function __construct(
        private ServerProvisionCommandBuilder $scriptBuilder,
        private AttachDatabaseEngineToServer $attach,
    ) {}

    /**
     * @param  (Closure(Server): RemoteShell)|null  $shellFactory  test seam
     * @return array{ok: bool, engine: string, output: string, row?: ServerDatabaseEngine}
     */
    public function execute(
        Server $server,
        string $engine,
        ?string $version = null,
        bool $isDefault = false,
        ?Closure $shellFactory = null,
    ): array {
        $engine = trim($engine);
        if ($engine === '') {
            throw new \InvalidArgumentException('Engine is required.');
        }

        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $lines = $this->scriptBuilder->installEngineLines($engine);
        if ($lines === []) {
            return [
                'ok' => false,
                'engine' => $engine,
                'output' => '',
            ];
        }

        $shell = $shellFactory !== null ? $shellFactory($server) : new SshConnection($server);
        $output = '';
        foreach ($lines as $line) {
            // Each line is a shell command; collect the output as we go.
            // Apt steps can take 30–120s on small droplets.
            $output .= $shell->exec($line, 300)."\n";
        }

        $row = $this->attach->execute($server, $engine, $version, $isDefault);

        return [
            'ok' => true,
            'engine' => $engine,
            'output' => $output,
            'row' => $row,
        ];
    }
}
