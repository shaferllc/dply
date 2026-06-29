<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Sites\TenantDnsProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Self-heals tenant custom-domain DNS: for every site with tenant domains, makes
 * sure each tenant hostname has an A record pointing at its server — wherever dply
 * holds a credential for that hostname's zone. Idempotent (upserts), so tenants
 * created before the on-add provisioning, or whose record drifted, get fixed
 * without an operator touching them. Scheduled hourly (see {@see \App\Console\Scheduling\DplySchedule}).
 */
class ReconcileTenantDnsCommand extends Command
{
    protected $signature = 'dply:tenants:reconcile-dns
        {--site= : Limit to one site (id or slug)}
        {--dry-run : List tenants that would be ensured without writing records}';

    protected $description = 'Ensure each tenant custom domain has an A record pointing at its server (where dply holds the zone credential).';

    public function handle(TenantDnsProvisioner $provisioner): int
    {
        $sites = Site::query()
            ->has('tenantDomains')
            ->with(['server', 'tenantDomains'])
            ->when($this->option('site'), function ($query, string $site): void {
                $query->where(fn ($where) => $where->where('id', $site)->orWhere('slug', $site));
            })
            ->get();

        $ensured = 0;
        $noCredential = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($sites as $site) {
            if (trim((string) ($site->server?->ip_address ?? '')) === '') {
                $skipped += $site->tenantDomains->count();

                continue;
            }

            foreach ($site->tenantDomains as $tenant) {
                if ((bool) $this->option('dry-run')) {
                    $this->line(sprintf('  [dry-run] %s · site %s', $tenant->hostname, $site->slug));

                    continue;
                }

                $result = $provisioner->ensure($site, $tenant);

                match ($result['status']) {
                    'created' => $ensured++,
                    'no_credential' => $noCredential++,
                    'error' => $failed++,
                    default => $skipped++,
                };

                if ($result['status'] === 'error') {
                    Log::warning('Tenant DNS reconcile failed', [
                        'site_id' => (string) $site->id,
                        'hostname' => $tenant->hostname,
                        'zone' => $result['zone'],
                        'error' => $result['message'],
                    ]);
                }
            }
        }

        $this->info(sprintf(
            'Tenant DNS reconcile: %d ensured, %d no-credential, %d failed, %d skipped.',
            $ensured,
            $noCredential,
            $failed,
            $skipped,
        ));

        return self::SUCCESS;
    }
}
