<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\HetznerService;
use App\Support\Servers\HetznerCloudFirewallRules;
use App\Support\Servers\ServerHostingPlatformContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Backfill the dply-managed Hetzner Cloud Firewall onto EXISTING Hetzner
 * servers (provisioned before firewall management existed).
 *
 *   dply:hetzner:ensure-firewall [server] [--dry-run]
 *
 * Without a server argument, targets every Hetzner server that has a
 * provider_id. Idempotent: find-or-create a firewall named `dply-<id>`,
 * sync its rules to the server's intended exposure, attach it, and record
 * the id in meta. The firewall is ADDITIVE at Hetzner (rules union across
 * attached firewalls), so this never reduces access to an already-reachable
 * box — any firewall you maintain by hand keeps working.
 */
class HetznerEnsureFirewallCommand extends Command
{
    protected $signature = 'dply:hetzner:ensure-firewall {server? : Server id (defaults to all Hetzner servers)} {--dry-run : Show the rules that would be applied without calling the Hetzner API}';

    protected $description = 'Ensure + attach the dply-managed Cloud Firewall on existing Hetzner servers.';

    public function handle(): int
    {
        $query = Server::query()->where('provider', 'hetzner');
        if ($serverId = $this->argument('server')) {
            $query->whereKey($serverId);
        }

        $servers = $query->get();
        if ($servers->isEmpty()) {
            $this->warn('No matching Hetzner servers found.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $failures = 0;

        foreach ($servers as $server) {
            $label = sprintf('%s (%s)', $server->name ?: $server->id, $server->id);

            if (empty($server->provider_id)) {
                $this->warn("• {$label}: no provider_id (never finished cloud-create) — skipping.");

                continue;
            }

            $rules = HetznerCloudFirewallRules::forServer($server);
            $summary = collect($rules)
                ->map(fn ($r) => ($r['protocol'] === 'icmp' ? 'icmp' : $r['port']).'←'.implode(',', $r['source_ips']))
                ->implode('  ');

            if ($dryRun) {
                $this->line("• {$label}: would apply [{$summary}]");

                continue;
            }

            $hetzner = $this->resolveClient($server);
            if (! $hetzner) {
                $this->error("• {$label}: could not resolve a Hetzner API client (no managed token / linked credential).");
                $failures++;

                continue;
            }

            try {
                $firewallName = 'dply-'.$server->id;
                $existing = $hetzner->findFirewallByName($firewallName);
                if ($existing !== null && isset($existing['id'])) {
                    $firewallId = (int) $existing['id'];
                    $hetzner->setFirewallRules($firewallId, $rules);
                } else {
                    $firewallId = $hetzner->createFirewall($firewallName, $rules);
                }

                $hetzner->applyFirewallToServer($firewallId, (int) $server->provider_id);

                $meta = $server->meta;
                $meta['hetzner_firewall_id'] = $firewallId;
                $server->update(['meta' => $meta]);

                $this->info("• {$label}: firewall #{$firewallId} applied [{$summary}]");
            } catch (Throwable $e) {
                $this->error("• {$label}: {$e->getMessage()}");
                $failures++;
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function resolveClient(Server $server): ?HetznerService
    {
        if ($server->usesManagedHosting()) {
            $platform = ServerHostingPlatformContext::fromConfig();

            return $platform->configured() ? $platform->hetzner() : null;
        }

        return $server->providerCredential
            ? new HetznerService($server->providerCredential)
            : null;
    }
}
