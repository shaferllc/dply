<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Cloud\Services\VultrService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Provision the dply control-plane VM topology on Vultr (web + worker +
 * Postgres + Redis) inside one VPC. Writes an inventory JSON for cutover /
 * bootstrap. Idempotent on labels: skips roles that already exist.
 *
 * @see docs/dply-production-runtime.md
 */
class VultrControlPlaneProvisionCommand extends Command
{
    protected $signature = 'dply:vultr:control-plane:provision
        {--region=lax : Vultr region id}
        {--execute : Actually create VPC/instances (default is dry-run)}
        {--ssh-pubkey= : Path to SSH public key to install (default ~/.ssh/id_ed25519.pub)}
        {--inventory= : Inventory JSON path (default storage/app/vultr-control-plane.json)}';

    protected $description = 'Provision Vultr VMs for the dply control plane (web/worker/db/redis)';

    /** @var array<string, array{label: string, plan: string, os_id: int}> */
    private const ROLES = [
        'web' => [
            'label' => 'dply-app',
            'plan' => 'vc2-2c-4gb',
            'os_id' => 2284,
        ],
        'worker' => [
            'label' => 'dply-worker-1',
            'plan' => 'vc2-4c-8gb',
            'os_id' => 2284,
        ],
        'postgres' => [
            'label' => 'dply-database-1',
            'plan' => 'vc2-4c-8gb',
            'os_id' => 2284,
        ],
        'redis' => [
            'label' => 'dply-redis-1',
            'plan' => 'vc2-2c-4gb',
            'os_id' => 2284,
        ],
    ];

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $region = trim((string) $this->option('region'));
        $inventoryPath = (string) ($this->option('inventory') ?: storage_path('app/vultr-control-plane.json'));

        $token = trim((string) config('services.vultr.token', ''));
        if ($token === '') {
            $this->error('Set VULTR_TOKEN / services.vultr.token before provisioning.');

            return self::FAILURE;
        }

        $home = $_SERVER['HOME'] ?? getenv('HOME');
        $home = is_string($home) ? $home : '';
        $pubkeyPath = (string) ($this->option('ssh-pubkey') ?: $home.'/.ssh/id_ed25519.pub');
        $pubkey = is_readable($pubkeyPath) ? trim((string) file_get_contents($pubkeyPath)) : '';
        if ($pubkey === '') {
            $this->error("SSH public key not readable: {$pubkeyPath}");

            return self::FAILURE;
        }

        $vultr = VultrService::fromToken($token);
        $existingByLabel = $this->indexInstancesByLabel($vultr);

        $this->info(($execute ? 'EXECUTE' : 'DRY-RUN')." control-plane provision in region={$region}");
        $this->table(
            ['Role', 'Label', 'Plan', 'Status'],
            collect(self::ROLES)->map(function (array $spec, string $role) use ($existingByLabel): array {
                $exists = isset($existingByLabel[$spec['label']]);

                return [$role, $spec['label'], $spec['plan'], $exists ? 'exists' : 'create'];
            })->all(),
        );

        if (! $execute) {
            $this->warn('Re-run with --execute to create the VPC + instances.');

            return self::SUCCESS;
        }

        $vpcId = $this->ensureVpc($vultr, $region);
        $firewallGroupId = $this->ensureFirewallGroup($vultr);
        $sshKeyId = $vultr->createSshKey('dply-control-plane-'.substr(md5($pubkey), 0, 8), $pubkey);

        $hosts = [];
        foreach (self::ROLES as $role => $spec) {
            if (isset($existingByLabel[$spec['label']])) {
                $inst = $existingByLabel[$spec['label']];
                $hosts[$role] = $this->hostRecord($role, $spec, $inst, $vpcId);
                $hosts[$role]['firewall_group_id'] = $firewallGroupId;
                $this->line("skip {$role}: already exists as {$inst['id']}");
                $this->attachFirewallIfNeeded($vultr, (string) ($inst['id'] ?? ''), $firewallGroupId, (string) ($inst['firewall_group_id'] ?? ''));

                continue;
            }

            $this->line("creating {$role} ({$spec['label']}, {$spec['plan']})…");
            try {
                $id = $vultr->createInstance(
                    region: $region,
                    plan: $spec['plan'],
                    osId: $spec['os_id'],
                    label: $spec['label'],
                    sshKeyIds: [$sshKeyId],
                    vpcIds: [$vpcId],
                );
                $this->attachFirewallIfNeeded($vultr, $id, $firewallGroupId, '');
            } catch (Throwable $e) {
                $this->error("Failed creating {$role}: {$e->getMessage()}");

                return self::FAILURE;
            }

            $hosts[$role] = [
                'role' => $role,
                'label' => $spec['label'],
                'plan' => $spec['plan'],
                'instance_id' => $id,
                'ip_address' => null,
                'private_ip_address' => null,
                'vpc_id' => $vpcId,
                'region' => $region,
                'os_id' => $spec['os_id'],
                'firewall_group_id' => $firewallGroupId,
            ];
        }

        $this->info('Waiting for public IPs…');
        for ($i = 0; $i < 60; $i++) {
            $pending = false;
            foreach ($hosts as $role => $host) {
                if (filled($host['ip_address'] ?? null)) {
                    continue;
                }
                try {
                    $inst = $vultr->getInstance((string) $host['instance_id']);
                } catch (Throwable) {
                    $pending = true;

                    continue;
                }
                $ip = VultrService::getPublicIp($inst);
                $private = VultrService::getPrivateIp($inst);
                if ($ip) {
                    $hosts[$role]['ip_address'] = $ip;
                    $hosts[$role]['private_ip_address'] = $private;
                    $this->line("  {$role}: {$ip}".($private ? " (private {$private})" : ''));
                } else {
                    $pending = true;
                }
            }
            if (! $pending) {
                break;
            }
            sleep(5);
        }

        $inventory = [
            'provider' => 'vultr',
            'region' => $region,
            'vpc_id' => $vpcId,
            'firewall_group_id' => $firewallGroupId,
            'provisioned_at' => now()->toIso8601String(),
            'hosts' => $hosts,
            'bootstrap' => [
                'web_root' => '/home/dply/dply',
                'worker_root' => '/home/dply/worker-1.dply.io',
                'runtime_docs' => 'docs/dply-production-runtime.md',
                'env_sync' => 'deploy/ENV_SYNC.md',
                'next' => [
                    'php artisan dply:vultr:control-plane:bootstrap --execute',
                    'On workers after app deploy: dply:edge:ensure-build-docker',
                    'Run dply:vultr:control-plane:cutover --checklist',
                ],
            ],
        ];

        File::ensureDirectoryExists(dirname($inventoryPath));
        File::put($inventoryPath, json_encode($inventory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $this->info("Wrote inventory: {$inventoryPath}");

        return self::SUCCESS;
    }

    private function ensureVpc(VultrService $vultr, string $region): string
    {
        foreach ($vultr->listVpcs($region) as $vpc) {
            if (str_contains(strtolower($vpc['description']), 'dply-control')) {
                $this->line("reusing VPC {$vpc['id']} ({$vpc['description']})");

                return $vpc['id'];
            }
        }

        $this->line('creating VPC dply-control-plane…');

        return $vultr->createVpc($region, 'dply-control-plane', '10.50.0.0', 24);
    }

    private function ensureFirewallGroup(VultrService $vultr): string
    {
        $existing = $vultr->findFirewallGroupByDescription('dply-control-plane');
        if ($existing !== null) {
            $this->line("reusing firewall group {$existing}");

            return $existing;
        }

        $this->line('creating firewall group dply-control-plane…');
        $id = $vultr->createFirewallGroup('dply-control-plane');
        foreach ([
            ['protocol' => 'tcp', 'port' => '22', 'subnet' => '0.0.0.0', 'subnet_size' => 0, 'notes' => 'SSH'],
            ['protocol' => 'tcp', 'port' => '80', 'subnet' => '0.0.0.0', 'subnet_size' => 0, 'notes' => 'HTTP'],
            ['protocol' => 'tcp', 'port' => '443', 'subnet' => '0.0.0.0', 'subnet_size' => 0, 'notes' => 'HTTPS'],
            ['protocol' => 'icmp', 'port' => '', 'subnet' => '0.0.0.0', 'subnet_size' => 0, 'notes' => 'ICMP'],
            ['protocol' => 'tcp', 'port' => '5432', 'subnet' => '10.50.0.0', 'subnet_size' => 24, 'notes' => 'Postgres VPC'],
            ['protocol' => 'tcp', 'port' => '6379', 'subnet' => '10.50.0.0', 'subnet_size' => 24, 'notes' => 'Redis VPC'],
        ] as $rule) {
            $vultr->createFirewallRule($id, $rule);
        }

        return $id;
    }

    private function attachFirewallIfNeeded(VultrService $vultr, string $instanceId, string $firewallGroupId, string $current): void
    {
        if ($instanceId === '' || $firewallGroupId === '' || $current === $firewallGroupId) {
            return;
        }
        try {
            $vultr->attachFirewallGroup($instanceId, $firewallGroupId);
        } catch (Throwable $e) {
            $this->warn("firewall attach {$instanceId}: {$e->getMessage()}");
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function indexInstancesByLabel(VultrService $vultr): array
    {
        $out = [];
        foreach ($vultr->listInstances() as $inst) {
            $label = trim((string) ($inst['label'] ?? ''));
            if ($label !== '') {
                $out[$label] = $inst;
            }
        }

        return $out;
    }

    /**
     * @param  array{label: string, plan: string, os_id: int}  $spec
     * @param  array<string, mixed>  $inst
     * @return array<string, mixed>
     */
    private function hostRecord(string $role, array $spec, array $inst, string $vpcId): array
    {
        return [
            'role' => $role,
            'label' => $spec['label'],
            'plan' => $spec['plan'],
            'instance_id' => (string) ($inst['id'] ?? ''),
            'ip_address' => VultrService::getPublicIp($inst),
            'private_ip_address' => VultrService::getPrivateIp($inst),
            'vpc_id' => VultrService::getInstanceVpcId($inst) ?? $vpcId,
            'region' => (string) ($inst['region'] ?? ''),
            'os_id' => $spec['os_id'],
        ];
    }
}
