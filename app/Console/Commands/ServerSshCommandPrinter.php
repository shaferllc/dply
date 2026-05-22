<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Server;
use Illuminate\Console\Command;

/**
 * Print the SSH command needed to connect to a server.
 *
 *   dply:server:ssh <server>            # ssh dply@1.2.3.4
 *   dply:server:ssh <server> --root     # ssh root@1.2.3.4
 *   dply:server:ssh <server> --json
 *
 * Print-only — does NOT initiate the connection itself, since the
 * private keys are stored encrypted in the DB and we shouldn't shove
 * them onto disk for ad-hoc CLI use. Operators run the printed
 * command themselves with their own configured SSH agent.
 *
 * Use case: `eval "$(dply:server:ssh prod-1)"` is risky, but
 * copy-pasting from `dply:server:ssh prod-1` to a terminal is the
 * common workflow.
 */
class ServerSshCommandPrinter extends Command
{
    protected $signature = 'dply:server:ssh
        {server : Server ID, name, or IP}
        {--root : Print as root@ instead of the configured deploy user}
        {--json : Output as JSON}';

    protected $description = 'Print the SSH command to connect to a server.';

    public function handle(): int
    {
        $needle = (string) $this->argument('server');
        $server = $this->resolveServer($needle);
        if ($server === null) {
            $this->error("Server not found: {$needle}");

            return self::FAILURE;
        }

        $host = trim((string) $server->ip_address);
        if ($host === '') {
            $this->error('Server has no IP address configured.');

            return self::FAILURE;
        }

        $port = (int) ($server->ssh_port ?: 22);
        $user = (bool) $this->option('root')
            ? 'root'
            : (string) config('server_provision.deploy_ssh_user', 'dply');

        $cmd = $port === 22
            ? sprintf('ssh %s@%s', $user, $host)
            : sprintf('ssh -p %d %s@%s', $port, $user, $host);

        if ($this->option('json')) {
            $this->line(json_encode([
                'server_id' => $server->id,
                'server_name' => $server->name,
                'host' => $host,
                'port' => $port,
                'user' => $user,
                'command' => $cmd,
            ], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->line($cmd);

        return self::SUCCESS;
    }

    private function resolveServer(string $needle): ?Server
    {
        $needle = trim($needle);
        if ($needle === '') {
            return null;
        }

        return Server::query()
            ->where('id', $needle)
            ->orWhere('name', $needle)
            ->orWhere('ip_address', $needle)
            ->first();
    }
}
