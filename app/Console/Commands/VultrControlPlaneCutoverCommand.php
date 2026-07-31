<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Prints / validates the Hetzner → Vultr control-plane cutover checklist.
 * Does not flip DNS or drain Hetzner by itself — those are deliberate prod ops.
 */
class VultrControlPlaneCutoverCommand extends Command
{
    protected $signature = 'dply:vultr:control-plane:cutover
        {--checklist : Print the cutover checklist}
        {--inventory= : Inventory JSON from dply:vultr:control-plane:provision}';

    protected $description = 'Validate Vultr control-plane inventory and print cutover steps';

    public function handle(): int
    {
        $inventoryPath = (string) ($this->option('inventory') ?: storage_path('app/vultr-control-plane.json'));
        if (! is_readable($inventoryPath)) {
            $this->error("Inventory missing: {$inventoryPath}");
            $this->line('Run: php artisan dply:vultr:control-plane:provision --execute');

            return self::FAILURE;
        }

        /** @var array<string, mixed> $inventory */
        $inventory = json_decode((string) File::get($inventoryPath), true) ?: [];
        $hosts = is_array($inventory['hosts'] ?? null) ? $inventory['hosts'] : [];

        $rows = [];
        $ok = true;
        foreach (['web', 'worker', 'postgres', 'redis'] as $role) {
            $host = is_array($hosts[$role] ?? null) ? $hosts[$role] : [];
            $ip = trim((string) ($host['ip_address'] ?? ''));
            $priv = trim((string) ($host['private_ip_address'] ?? ''));
            if ($ip === '') {
                $ok = false;
            }
            $rows[] = [$role, (string) ($host['label'] ?? ''), $ip !== '' ? $ip : 'MISSING', $priv !== '' ? $priv : '—'];
        }

        $this->table(['Role', 'Label', 'Public IP', 'Private IP'], $rows);
        $this->line('VPC: '.((string) ($inventory['vpc_id'] ?? '—')));
        $this->line('Region: '.((string) ($inventory['region'] ?? '—')));
        $bootstrapped = trim((string) data_get($inventory, 'bootstrap.bootstrapped_at', ''));
        $this->line('Bootstrap: '.($bootstrapped !== '' ? "done at {$bootstrapped}" : 'pending (dply:vultr:control-plane:bootstrap --execute)'));

        $webIp = trim((string) data_get($hosts, 'web.ip_address', ''));

        if ($this->option('checklist') || true) {
            $this->newLine();
            $this->info('Cutover checklist (manual / deliberate — this command does not flip DNS or migrate prod):');
            foreach ([
                '1. [infra] Bootstrap packages + atomic layout (php artisan dply:vultr:control-plane:bootstrap --execute)'.($bootstrapped !== '' ? ' ✓' : ''),
                '2. [infra] Postgres/Redis on private VPC IPs (done by bootstrap)'.($bootstrapped !== '' ? ' ✓' : ''),
                '3. Copy LIVE SHARED⊕overlay .env from Hetzner with identical APP_KEY (deploy/ENV_SYNC.md) — replace staging secrets',
                '4. Deploy app release onto web+worker current/; run migrations on worker; horizon + nginx',
                '5. Staging smoke against Vultr web IP (hosts file or temp CF record): login, BYO SSH, Edge Docker, Horizon',
                '6. Maintenance window: pg_dump Hetzner dply-database-1 → restore Vultr Postgres (10.50.0.5)',
                '7. Flip Cloudflare DNS for dply.io → '.($webIp !== '' ? $webIp : 'Vultr web public IP').' (proxy on)',
                '8. Verify Stripe/OAuth/TaskRunner webhooks against https://dply.io',
                '9. Keep Hetzner boxes 24–48h healthy, then destroy',
            ] as $step) {
                $this->line($step);
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}