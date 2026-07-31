<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * SSH-bootstrap Vultr control-plane VMs from inventory JSON:
 * common packages, Postgres/Redis on private IPs, atomic layout + shared/.env.
 *
 * @see deploy/vultr-control-plane/
 * @see docs/dply-production-runtime.md
 */
class VultrControlPlaneBootstrapCommand extends Command
{
    protected $signature = 'dply:vultr:control-plane:bootstrap
        {--inventory= : Inventory JSON from dply:vultr:control-plane:provision}
        {--ssh-key= : Private key path (default ~/.ssh/dply_vultr_cp)}
        {--execute : Run remote bootstrap (default dry-run)}
        {--role= : Only bootstrap one role (web|worker|postgres|redis)}';

    protected $description = 'Bootstrap Vultr control-plane VMs (packages, DB/Redis, atomic layout, env)';

    public function handle(): int
    {
        $inventoryPath = (string) ($this->option('inventory') ?: storage_path('app/vultr-control-plane.json'));
        $execute = (bool) $this->option('execute');
        $onlyRole = trim((string) $this->option('role'));
        $sshKey = (string) ($this->option('ssh-key') ?: (($_SERVER['HOME'] ?? getenv('HOME') ?: '').'/.ssh/dply_vultr_cp'));

        if (! is_readable($inventoryPath)) {
            $this->error("Inventory missing: {$inventoryPath}");

            return self::FAILURE;
        }
        if (! is_readable($sshKey)) {
            $this->error("SSH private key missing: {$sshKey}");
            $this->line('Generate with: ssh-keygen -t ed25519 -N "" -f ~/.ssh/dply_vultr_cp');

            return self::FAILURE;
        }

        /** @var array<string, mixed> $inventory */
        $inventory = json_decode((string) File::get($inventoryPath), true) ?: [];
        $hosts = is_array($inventory['hosts'] ?? null) ? $inventory['hosts'] : [];

        $secretsPath = storage_path('app/vultr-control-plane.secrets.json');
        $secrets = $this->loadOrCreateSecrets($secretsPath, $inventory);

        $order = ['postgres', 'redis', 'web', 'worker'];
        if ($onlyRole !== '') {
            $order = [$onlyRole];
        }

        foreach ($order as $role) {
            $host = is_array($hosts[$role] ?? null) ? $hosts[$role] : null;
            if ($host === null) {
                $this->error("Role missing from inventory: {$role}");

                return self::FAILURE;
            }
            $ip = trim((string) ($host['ip_address'] ?? ''));
            $priv = trim((string) ($host['private_ip_address'] ?? ''));
            if ($ip === '' || $priv === '') {
                $this->error("{$role} needs public + private IP in inventory (refresh after VPC attach).");

                return self::FAILURE;
            }

            $this->info(($execute ? 'BOOTSTRAP' : 'DRY-RUN')." {$role} {$ip} (private {$priv})");
            if (! $execute) {
                continue;
            }

            try {
                $this->bootstrapRole($role, $host, $secrets, $sshKey, $inventory);
            } catch (Throwable $e) {
                $this->error("{$role} failed: {$e->getMessage()}");

                return self::FAILURE;
            }
            $this->line("  ✓ {$role}");
        }

        if (! $execute) {
            $this->warn('Re-run with --execute to apply remote bootstrap.');
            $this->line("Secrets file (local only): {$secretsPath}");

            return self::SUCCESS;
        }

        $inventory['bootstrap']['bootstrapped_at'] = now()->toIso8601String();
        $inventory['bootstrap']['secrets_file'] = $secretsPath;
        $inventory['bootstrap']['ssh_key'] = $sshKey;
        File::put($inventoryPath, json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $this->info("Updated inventory: {$inventoryPath}");
        $this->warn('Cutover still requires copying live APP_KEY/env from Hetzner (deploy/ENV_SYNC.md) before DNS flip.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $inventory
     * @return array{app_key: string, db_password: string, redis_password: string}
     */
    private function loadOrCreateSecrets(string $path, array $inventory): array
    {
        if (is_readable($path)) {
            /** @var array<string, mixed> $existing */
            $existing = json_decode((string) File::get($path), true) ?: [];
            if (filled($existing['app_key'] ?? null) && filled($existing['db_password'] ?? null) && filled($existing['redis_password'] ?? null)) {
                return [
                    'app_key' => (string) $existing['app_key'],
                    'db_password' => (string) $existing['db_password'],
                    'redis_password' => (string) $existing['redis_password'],
                ];
            }
        }

        $secrets = [
            'app_key' => 'base64:'.base64_encode(random_bytes(32)),
            'db_password' => Str::password(32, symbols: false),
            'redis_password' => Str::password(32, symbols: false),
            'created_at' => now()->toIso8601String(),
            'note' => 'Staging secrets for Vultr control plane. Replace APP_KEY with Hetzner production value at cutover.',
            'region' => $inventory['region'] ?? null,
            'vpc_id' => $inventory['vpc_id'] ?? null,
        ];
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($secrets, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $this->warn("Wrote new secrets: {$path}");

        return [
            'app_key' => $secrets['app_key'],
            'db_password' => $secrets['db_password'],
            'redis_password' => $secrets['redis_password'],
        ];
    }

    /**
     * @param  array<string, mixed>  $host
     * @param  array{app_key: string, db_password: string, redis_password: string}  $secrets
     * @param  array<string, mixed>  $inventory
     */
    private function bootstrapRole(string $role, array $host, array $secrets, string $sshKey, array $inventory): void
    {
        $ip = (string) $host['ip_address'];
        $priv = (string) $host['private_ip_address'];
        $scripts = base_path('deploy/vultr-control-plane');
        $remoteDir = '/tmp/dply-cp-bootstrap';

        $this->ssh($sshKey, $ip, "mkdir -p {$remoteDir}");
        foreach (['bootstrap-common.sh', 'bootstrap-postgres.sh', 'bootstrap-redis.sh', 'bootstrap-app-layout.sh'] as $file) {
            $this->scp($sshKey, "{$scripts}/{$file}", "root@{$ip}:{$remoteDir}/{$file}");
        }
        $this->ssh($sshKey, $ip, "chmod +x {$remoteDir}/*.sh");

        $env = [
            'DPLY_HOSTNAME='.escapeshellarg((string) ($host['label'] ?? $role)),
            'DPLY_PRIVATE_IP='.escapeshellarg($priv),
            'DPLY_VPC_CIDR='.escapeshellarg('10.50.0.0/24'),
            'DPLY_DB_PASSWORD='.escapeshellarg($secrets['db_password']),
            'DPLY_REDIS_PASSWORD='.escapeshellarg($secrets['redis_password']),
            'DPLY_APP_KEY='.escapeshellarg($secrets['app_key']),
            'DPLY_DB_HOST='.escapeshellarg((string) data_get($inventory, 'hosts.postgres.private_ip_address', '')),
            'DPLY_REDIS_HOST='.escapeshellarg((string) data_get($inventory, 'hosts.redis.private_ip_address', '')),
        ];

        $this->ssh($sshKey, $ip, implode(' ', $env)." {$remoteDir}/bootstrap-common.sh");

        if ($role === 'postgres') {
            $this->ssh($sshKey, $ip, implode(' ', $env)." {$remoteDir}/bootstrap-postgres.sh");

            return;
        }
        if ($role === 'redis') {
            $this->ssh($sshKey, $ip, implode(' ', $env)." {$remoteDir}/bootstrap-redis.sh");

            return;
        }

        $root = $role === 'web' ? '/home/dply/dply' : '/home/dply/worker-1.dply.io';
        $runtime = $role === 'web' ? 'web' : 'worker';
        $extra = [
            'DPLY_APP_ROOT='.escapeshellarg($root),
            'DPLY_RUNTIME='.escapeshellarg($runtime),
        ];
        if ($role === 'worker') {
            $extra[] = 'DPLY_WORKER_ROLE='.escapeshellarg('primary');
        }
        $this->ssh($sshKey, $ip, implode(' ', array_merge($env, $extra))." {$remoteDir}/bootstrap-app-layout.sh");
    }

    private function ssh(string $key, string $ip, string $remoteCommand): void
    {
        $process = new Process([
            'ssh',
            '-o', 'BatchMode=yes',
            '-o', 'StrictHostKeyChecking=accept-new',
            '-o', 'IdentitiesOnly=yes',
            '-i', $key,
            "root@{$ip}",
            $remoteCommand,
        ]);
        $process->setTimeout(1800);
        $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });
        if (! $process->isSuccessful()) {
            throw new \RuntimeException(trim($process->getErrorOutput() ?: $process->getOutput()) ?: 'ssh failed');
        }
    }

    private function scp(string $key, string $local, string $remote): void
    {
        $process = new Process([
            'scp',
            '-o', 'BatchMode=yes',
            '-o', 'StrictHostKeyChecking=accept-new',
            '-o', 'IdentitiesOnly=yes',
            '-i', $key,
            $local,
            $remote,
        ]);
        $process->setTimeout(120);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new \RuntimeException(trim($process->getErrorOutput() ?: 'scp failed'));
        }
    }
}
