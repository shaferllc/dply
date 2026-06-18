<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ServerProvider;
use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseAdminCredential;
use Illuminate\Console\Command;

/**
 * Adopt dply's OWN prod control-plane as a managed Server (W2 dogfood). Creates
 * or syncs the Server record (SSH operational + recovery keys), the Postgres
 * admin credential, and a ServerDatabase row for the control-plane DB — so
 * dply's existing backup/cron features can manage it. Idempotent. Config comes
 * from config/self_manage.php (env-driven); see deploy/SELF_MANAGE.md.
 */
class SelfAdoptCommand extends Command
{
    protected $signature = 'dply:self:adopt {--dry-run : show what would change, write nothing}';

    protected $description = 'Register dply prod as a managed Server in dply (dogfood).';

    public function handle(): int
    {
        $cfg = (array) config('self_manage');
        $dry = (bool) $this->option('dry-run');

        $orgId = $cfg['organization_id'] ?? null;
        $server = (array) ($cfg['server'] ?? []);
        $ip = $server['ip_address'] ?? null;
        $opKeyPath = $server['operational_key_path'] ?? null;

        if (! $orgId || ! $ip || ! $opKeyPath) {
            $this->error('Missing required self_manage config: organization_id, server.ip_address, server.operational_key_path.');

            return self::FAILURE;
        }

        $opKey = $this->readKey($opKeyPath);
        if ($opKey === null) {
            $this->error("Operational SSH key not readable: {$opKeyPath}");

            return self::FAILURE;
        }
        $recoveryKey = isset($server['recovery_key_path']) ? $this->readKey((string) $server['recovery_key_path']) : null;

        // Resolve the control-plane DB from the app's own connection config.
        $conn = $cfg['db_connection'] ?? (string) config('database.default');
        $dbCfg = (array) config("database.connections.{$conn}");
        if (($dbCfg['driver'] ?? null) !== 'pgsql') {
            $this->error("Control-plane connection '{$conn}' is not pgsql.");

            return self::FAILURE;
        }

        $name = (string) ($server['name'] ?? 'dply-control-plane');

        if ($dry) {
            $this->info('[dry-run] Would adopt:');
            $this->line("  Server: {$name} @ {$ip} (provider=custom, self_managed)");
            $this->line("  ServerDatabase: {$dbCfg['database']} (postgres) on conn {$conn}");
            $this->line('  AdminCredential: postgres superuser '.(string) ($cfg['postgres']['superuser'] ?? 'postgres'));

            return self::SUCCESS;
        }

        $serverModel = Server::query()->updateOrCreate(
            ['organization_id' => $orgId, 'name' => $name],
            [
                'user_id' => $cfg['user_id'] ?? null,
                'workspace_id' => $cfg['workspace_id'] ?? null,
                'provider' => ServerProvider::Custom,
                'ip_address' => $ip,
                'ssh_port' => (int) ($server['ssh_port'] ?? 22),
                'ssh_user' => (string) ($server['ssh_user'] ?? 'dply'),
                'ssh_operational_private_key' => $opKey,
                'ssh_recovery_private_key' => $recoveryKey,
                'status' => Server::STATUS_READY,
                'meta' => ['self_managed' => true],
            ],
        );

        // A self-adopted org runs dply's own control plane — exempt it from the
        // trial/pause ladder so the platform can never bill-pause itself.
        Organization::query()->whereKey($orgId)->update(['is_internal' => true]);

        ServerDatabaseAdminCredential::query()->updateOrCreate(
            ['server_id' => $serverModel->id],
            [
                'postgres_superuser' => (string) ($cfg['postgres']['superuser'] ?? 'postgres'),
                'postgres_password' => $cfg['postgres']['password'] ?? null,
                'postgres_use_sudo' => (bool) ($cfg['postgres']['use_sudo'] ?? false),
            ],
        );

        ServerDatabase::query()->updateOrCreate(
            ['server_id' => $serverModel->id, 'name' => (string) ($dbCfg['database'] ?? '')],
            [
                'engine' => 'postgres',
                'username' => (string) ($dbCfg['username'] ?? ''),
                'password' => (string) ($dbCfg['password'] ?? ''),
                'host' => (string) ($dbCfg['host'] ?? '127.0.0.1'),
                'description' => 'dply control-plane database (self-managed)',
            ],
        );

        $this->info("Adopted prod as Server {$serverModel->id} ({$name}).");
        $this->line('Next: add this server_id to secret_vault.drift.targets and create a ServerBackupSchedule for its database.');

        return self::SUCCESS;
    }

    private function readKey(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }
        $contents = trim((string) file_get_contents($path));

        return $contents !== '' ? $contents : null;
    }
}
