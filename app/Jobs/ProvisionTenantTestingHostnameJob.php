<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Jobs\Concerns\WritesConsoleAction;
use App\Models\Site;
use App\Models\SiteTenantDomain;
use App\Services\Sites\TestingHostnameProvisioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
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
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, WritesConsoleAction;

    public int $tries = 1;

    public function __construct(
        public string $siteId,
        public string $tenantId,
        public bool $remove = false,
        public ?string $userId = null,
        public bool $deleteTenantRow = false,
        public ?string $seededConsoleRunId = null,
    ) {}

    protected function consoleSubject(): Model
    {
        return Site::query()->findOrFail($this->siteId);
    }

    protected function consoleKind(): string
    {
        return 'tenant_dns';
    }

    protected function triggeringUserId(): ?string
    {
        return $this->userId !== null && $this->userId !== '' ? $this->userId : null;
    }

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

        // Opt-in live banner when the UI seeded a run; otherwise silent as before.
        $emit = null;
        if ($this->seededConsoleRunId !== null) {
            $this->bindConsoleRunId($this->seededConsoleRunId);
            $emit = $this->beginConsoleAction();
        }

        $host = (string) $tenant->hostname;

        // DNS step is best-effort — a provider hiccup shouldn't block removing the
        // row or re-applying the vhost, so it's logged + noted, never thrown.
        try {
            if ($this->remove) {
                $emit?->step('tenant', 'Removing the managed testing hostname for '.$host.' …');
                $provisioner->deleteForTenant($site, $tenant);
            } else {
                $emit?->step('tenant', 'Provisioning a managed testing hostname for '.$host.' …');
                $provisioner->provisionForTenant($site, $tenant);
            }
        } catch (\Throwable $e) {
            Log::warning('ProvisionTenantTestingHostnameJob failed', [
                'site_id' => $this->siteId,
                'tenant_id' => $this->tenantId,
                'remove' => $this->remove,
                'error' => $e->getMessage(),
            ]);
            $emit?->step('tenant', 'DNS step did not complete ('.$e->getMessage().') — continuing.');
        }

        // When the whole tenant is being deleted, drop the row only after its DNS
        // record is cleaned up — keeps the deletion + cleanup off the web request.
        if ($this->remove && $this->deleteTenantRow) {
            $emit?->step('tenant', 'Removing the tenant '.$host.' …');
            $tenant->delete();
        }

        // Re-apply the managed webserver config so the tenant testing hostname is
        // added to (or removed from) the vhost server_name on the box.
        $emit?->step('tenant', 'Re-applying the webserver config …');
        ApplySiteWebserverConfigJob::dispatch($site->id, $this->userId);

        $emit?->success(
            $this->remove
                ? ($this->deleteTenantRow ? 'Tenant '.$host.' removed.' : 'Testing URL removed.')
                : 'Testing URL ready.',
            'tenant',
        );
        $this->completeConsoleAction();
    }
}
