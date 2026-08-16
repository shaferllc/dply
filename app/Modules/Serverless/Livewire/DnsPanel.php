<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Livewire;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Site;
use App\Modules\Cloud\Cloudflare\CloudflareDnsService;
use App\Modules\Cloud\Services\DigitalOceanService;
use App\Modules\Serverless\Services\ServerlessFunctionDnsProvisioner;
use App\Modules\Serverless\Support\ServerlessTestingDomains;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Component;

/**
 * Surface and retry DNS provisioning for a serverless function's friendly
 * hostname ({slug}.dply-serverless.cloud). The deployer already calls the
 * provisioner on every deploy, but two cases make the result invisible:
 *  - Skipped (missing DIGITALOCEAN_TOKEN, or the apex isn't a zone the token
 *    owns) — operator never sees why the hostname doesn't resolve.
 *  - Failed (DO API errored, e.g. zone not actually owned by the token) —
 *    same problem.
 *
 * This panel renders the stored result from {@see site.meta.serverless.dns}
 * and lets the operator re-run the provisioner from the UI after fixing
 * the underlying configuration, without going through a full redeploy.
 */
class DnsPanel extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;

    public string $siteId = '';

    public function mount(Site $site): void
    {
        $this->authorize('view', $site);
        $this->siteId = $site->id;
    }

    private function site(): Site
    {
        return Site::findOrFail($this->siteId);
    }

    public function provisionNow(ServerlessFunctionDnsProvisioner $provisioner): void
    {
        $site = $this->site();
        $this->authorize('update', $site);

        $result = $provisioner->provision($site);

        if ($result === null) {
            $this->toastError(__('Cannot provision DNS — the function has no friendly hostname configured yet. Deploy first.'));

            return;
        }

        $status = (string) data_get($site->fresh()->meta, 'serverless.dns.status', 'unknown');
        match ($status) {
            'ready' => $this->toastSuccess(__('DNS record created. Resolution may take a minute to propagate.')),
            'failed' => $this->toastError(__('DNS provisioning failed. See the panel below for details.')),
            'skipped' => $this->toastError(__('DNS skipped — fix the missing DigitalOcean token or DPLY_SERVERLESS_TESTING_DOMAINS configuration, then retry.')),
            default => $this->toastSuccess($result),
        };
    }

    /**
     * Last-resort path: delete every record at the target name (regardless of
     * type), then retry provisioning. Used when the standard purge can't
     * resolve the conflict — most commonly because something at that name
     * was created via the provider's web UI or another tool, and our matcher
     * doesn't recognize it as something we should clear automatically.
     *
     * Deletes through whichever API hosts the zone: Cloudflare for the
     * serverless apex, DigitalOcean for legacy-pool hostnames.
     */
    public function forcePurgeAndProvision(DigitalOceanService $do, ServerlessFunctionDnsProvisioner $provisioner): void
    {
        $site = $this->site();
        $this->authorize('update', $site);

        $host = $site->serverlessFunctionHost();
        if ($host === null) {
            $this->toastError(__('Cannot force-purge — the function has no hostname yet.'));

            return;
        }

        $zone = $this->zoneForHost($host);
        if ($zone === null) {
            $this->toastError(__('Cannot force-purge — the function hostname is not in any configured testing domain.'));

            return;
        }
        $recordName = (string) Str::beforeLast($host, '.'.$zone);

        $deleted = ServerlessTestingDomains::dnsProviderForZone($zone) === 'cloudflare'
            ? $this->forcePurgeCloudflare($zone, $host)
            : $this->forcePurgeDigitalOcean($zone, $recordName);

        if ($deleted === null) {
            return;
        }

        $this->toastSuccess(__('Force-deleted :n record(s) at this name. Re-running the provisioner…', ['n' => $deleted]));
        $provisioner->provision($site);
    }

    /**
     * @return int|null  Null when the purge could not run (toast already sent).
     */
    private function forcePurgeCloudflare(string $zone, string $host): ?int
    {
        $token = ServerlessTestingDomains::cloudflareApiToken();
        if ($token === '') {
            $this->toastError(__('Cannot force-purge — no Cloudflare API token configured for this zone.'));

            return null;
        }

        $cloudflare = new CloudflareDnsService($token);
        $deleted = 0;

        foreach (['CNAME', 'A', 'AAAA', 'TXT'] as $type) {
            foreach ($cloudflare->listDnsRecords($zone, $type, $host) as $record) {
                $recordId = trim((string) ($record['id'] ?? ''));
                if ($recordId !== '') {
                    $cloudflare->deleteDnsRecord($zone, $recordId);
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    private function forcePurgeDigitalOcean(string $zone, string $recordName): ?int
    {
        $token = trim((string) config('services.digitalocean.token'));
        if ($token === '') {
            $this->toastError(__('Cannot force-purge — no DigitalOcean token configured.'));

            return null;
        }

        // Delete every record at this name. The instance the constructor
        // hands us is the app-scoped service; switch to a token-specific
        // instance for the actual API calls.
        $tokenScoped = new DigitalOceanService($token);
        $records = $tokenScoped->getDomainRecords($zone);
        $targets = [strtolower(trim($recordName)), strtolower(rtrim($recordName.'.'.$zone, '.'))];
        $deleted = 0;
        foreach ($records as $record) {
            $rname = strtolower(rtrim((string) ($record['name'] ?? ''), '.'));
            if (! in_array($rname, $targets, true)) {
                continue;
            }
            $recordId = (int) ($record['id'] ?? 0);
            if ($recordId > 0) {
                $tokenScoped->deleteDomainRecord($zone, $recordId);
                $deleted++;
            }
        }

        return $deleted;
    }

    private function zoneForHost(string $host): ?string
    {
        return ServerlessTestingDomains::zoneForHost($host);
    }

    public function render(): View
    {
        $site = $this->site();
        $serverless = is_array($site->meta['serverless'] ?? null) ? $site->meta['serverless'] : [];
        $dns = is_array($serverless['dns'] ?? null) ? $serverless['dns'] : [];
        $recordsAtName = is_array($dns['records_at_name'] ?? null) ? $dns['records_at_name'] : [];

        return view('livewire.serverless.dns-panel', [
            'host' => $site->serverlessFunctionHost(),
            'status' => (string) ($dns['status'] ?? 'pending'),
            'recordType' => (string) ($dns['record_type'] ?? ''),
            'recordData' => (string) ($dns['record_data'] ?? ''),
            'recordName' => (string) ($dns['record_name'] ?? ''),
            'zone' => (string) ($dns['zone'] ?? ''),
            'reason' => (string) ($dns['reason'] ?? ''),
            'error' => (string) ($dns['error'] ?? ''),
            'recordsAtName' => $recordsAtName,
            'coveredByWildcard' => (bool) ($dns['covered_by_wildcard'] ?? false),
            'wildcardMode' => ($dns['dns_provider'] ?? '') === 'wildcard',
            'provisionedAt' => $dns['provisioned_at'] ?? null,
        ]);
    }
}
