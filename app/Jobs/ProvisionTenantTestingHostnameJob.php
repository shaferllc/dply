<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Models\SiteTenantDomain;
use App\Services\Sites\TestingHostnameProvisioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Provision (or tear down) a managed testing-domain hostname for one tenant so
 * the app can be reached as that tenant on a dply testing zone before the
 * customer's real DNS is in place.
 *
 * Queued because it makes an external DNS API call and then re-applies the
 * webserver config over SSH — neither belongs in the web request. After the DNS
 * record is settled it chains {@see ApplySiteWebserverConfigJob} so the new (or
 * removed) hostname lands in the vhost server_name; it's already part of
 * {@see Site::webserverHostnames()}.
 */
class ProvisionTenantTestingHostnameJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public string $siteId,
        public string $tenantId,
        public bool $remove = false,
        public ?string $userId = null,
        public bool $deleteTenantRow = false,
    ) {}

    public function handle(TestingHostnameProvisioner $provisioner): void
    {
        $site = Site::query()->with('server')->find($this->siteId);
        if (! $site instanceof Site) {
            return;
        }

        $tenant = SiteTenantDomain::query()
            ->where('site_id', $site->id)
            ->find($this->tenantId);
        if (! $tenant instanceof SiteTenantDomain) {
            return;
        }

        try {
            if ($this->remove) {
                $provisioner->deleteForTenant($site, $tenant);
            } else {
                $provisioner->provisionForTenant($site, $tenant);
            }
        } catch (\Throwable $e) {
            Log::warning('ProvisionTenantTestingHostnameJob failed', [
                'site_id' => $this->siteId,
                'tenant_id' => $this->tenantId,
                'remove' => $this->remove,
                'error' => $e->getMessage(),
            ]);
        }

        // When the whole tenant is being deleted, drop the row only after its DNS
        // record is cleaned up — keeps the deletion + cleanup off the web request.
        if ($this->remove && $this->deleteTenantRow) {
            $tenant->delete();
        }

        // Re-apply the managed webserver config so the tenant testing hostname is
        // added to (or removed from) the vhost server_name on the box.
        ApplySiteWebserverConfigJob::dispatch($site->id, $this->userId);
    }
}
