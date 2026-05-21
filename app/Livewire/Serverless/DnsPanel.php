<?php

declare(strict_types=1);

namespace App\Livewire\Serverless;

use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Models\Site;
use App\Services\Serverless\ServerlessFunctionDnsProvisioner;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Surface and retry DNS provisioning for a serverless function's friendly
 * hostname ({slug}.{testing-domain}). The deployer already calls the
 * provisioner on every deploy, but two cases make the result invisible:
 *  - Skipped (missing DIGITALOCEAN_TOKEN or DPLY_TESTING_DOMAINS not set) —
 *    operator never sees why the hostname doesn't resolve.
 *  - Failed (DO API errored, e.g. zone not actually owned by the token) —
 *    same problem.
 *
 * This panel renders the stored result from {@see site.meta.serverless.dns}
 * and lets the operator re-run the provisioner from the UI after fixing
 * the underlying configuration, without going through a full redeploy.
 */
class DnsPanel extends Component
{
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
            'skipped' => $this->toastError(__('DNS skipped — fix the missing DigitalOcean token or DPLY_TESTING_DOMAINS configuration, then retry.')),
            default => $this->toastSuccess($result),
        };
    }

    /**
     * Last-resort path: delete every DO record at the target name (regardless
     * of type), then retry provisioning. Used when the standard purge can't
     * resolve the conflict — most commonly because something at that name
     * was created via DO's web UI or another tool, and our matcher doesn't
     * recognize it as something we should clear automatically.
     */
    public function forcePurgeAndProvision(\App\Services\DigitalOceanService $do, ServerlessFunctionDnsProvisioner $provisioner): void
    {
        $site = $this->site();
        $this->authorize('update', $site);

        $host = $site->serverlessFunctionHost();
        $token = trim((string) config('services.digitalocean.token'));
        if ($host === null || $token === '') {
            $this->toastError(__('Cannot force-purge — missing function hostname or DigitalOcean token.'));

            return;
        }

        $zone = $this->zoneForHost($host);
        if ($zone === null) {
            $this->toastError(__('Cannot force-purge — the function hostname is not in any configured testing domain.'));

            return;
        }
        $recordName = (string) \Illuminate\Support\Str::beforeLast($host, '.'.$zone);

        // Delete every record at this name. The instance the constructor
        // hands us is the app-scoped service; switch to a token-specific
        // instance for the actual API calls.
        $tokenScoped = new \App\Services\DigitalOceanService($token);
        $records = $tokenScoped->getDomainRecords($zone);
        $targets = [strtolower(trim($recordName)), strtolower(rtrim($recordName.'.'.$zone, '.'))];
        $deleted = 0;
        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }
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

        $this->toastSuccess(__('Force-deleted :n record(s) at this name. Re-running the provisioner…', ['n' => $deleted]));
        $provisioner->provision($site);
    }

    private function zoneForHost(string $host): ?string
    {
        $domains = (array) config('services.digitalocean.testing_domains', []);
        foreach ($domains as $domain) {
            $domain = strtolower(trim((string) $domain));
            if ($domain !== '' && str_ends_with($host, '.'.$domain)) {
                return $domain;
            }
        }

        return null;
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
            'provisionedAt' => $dns['provisioned_at'] ?? null,
        ]);
    }
}
