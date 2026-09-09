<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\ServerDatabaseAdminCredential;
use App\Models\ServerDatabaseEngine;
use App\Services\Servers\ExecuteRemoteTaskOnServer;
use App\Support\Servers\DatabaseEngineInstallScripts;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Close the open-`default`-user hole on an existing ClickHouse box.
 *
 * Stock ClickHouse ships `default` with no password and no network restriction,
 * and dply's installer never changed that — so on any log box provisioned before
 * this command existed, the firewall is the only thing between the internet and
 * every org's logs. New installs are handled in the install path; this is the
 * remediation for boxes already out there.
 *
 * Generates a password, applies it plus a loopback restriction over SSH, and
 * records the plaintext in ServerDatabaseAdminCredential (encrypted at rest) so
 * dply can still administer the engine.
 *
 *   php artisan dply:clickhouse:secure <server-id> [--show]
 */
class SecureClickHouseCommand extends Command
{
    protected $signature = 'dply:clickhouse:secure
                            {server : Server ULID running ClickHouse}
                            {--password= : Use this password instead of generating one}
                            {--show : Print the password (avoid on shared terminals)}';

    protected $description = 'Password-protect and loopback-restrict the ClickHouse `default` user on a server';

    public function handle(ExecuteRemoteTaskOnServer $executor): int
    {
        $server = Server::query()->find((string) $this->argument('server'));

        if ($server === null) {
            $this->error('Server not found.');

            return self::FAILURE;
        }

        $engine = ServerDatabaseEngine::query()
            ->where('server_id', $server->id)
            ->where('engine', 'clickhouse')
            ->where('status', ServerDatabaseEngine::STATUS_RUNNING)
            ->first();

        if ($engine === null) {
            $this->error('ClickHouse is not running on '.$server->name.'.');

            return self::FAILURE;
        }

        // An explicit password lets an operator align ClickHouse with a credential
        // they already manage elsewhere; otherwise generate one.
        $password = (string) ($this->option('password') ?: '');
        if ($password === '') {
            $password = Str::password(32, symbols: false);
        }

        $this->info('Securing ClickHouse on '.$server->name.' …');
        $this->line('  · password on `default`');
        $this->line('  · `default` restricted to 127.0.0.1 / ::1');

        $output = $executor->runInlineBash(
            $server,
            'clickhouse:secure-default-user',
            DatabaseEngineInstallScripts::secureClickHouseScript($password),
            timeoutSeconds: 180,
            asRoot: true,
        );

        if ($output->exitCode !== 0 || ! str_contains($output->buffer, 'dply-clickhouse-secured')) {
            $this->error('Hardening failed — ClickHouse left as it was.');
            $this->line(Str::limit(trim($output->buffer), 1200));

            return self::FAILURE;
        }

        // Store only after the box confirms, so a failed run never leaves dply
        // holding a credential the engine does not actually accept.
        $cred = ServerDatabaseAdminCredential::query()->firstOrNew(['server_id' => $server->id]);
        $cred->clickhouse_admin_username = 'default';
        $cred->clickhouse_admin_password = $password;
        $cred->save();

        $this->info('Done. `default` now requires a password and only answers on loopback.');
        $this->line('Credential stored (encrypted) on the server\'s admin-credential row.');

        if ($this->option('show')) {
            $this->newLine();
            $this->line('default password: '.$password);
        }

        $this->newLine();
        $this->comment('Remote readers must authenticate as a per-database user (e.g. dply_logs);');
        $this->comment('the aggregator\'s Vector sink is unaffected — it writes over 127.0.0.1.');

        return self::SUCCESS;
    }
}
