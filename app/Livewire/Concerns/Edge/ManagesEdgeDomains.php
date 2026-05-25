<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Edge;

use App\Models\Site;
use App\Services\Edge\EdgeCustomDomainProvisioner;
use App\Services\Edge\EdgeRouter;
use Livewire\Component;

/**
 * @phpstan-require-extends Component
 *
 * @property Site $site
 */
trait ManagesEdgeDomains
{
    public string $edge_domain_input = '';

    public function attachEdgeDomain(): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $hostname = strtolower(trim($this->edge_domain_input));
        $hostname = preg_replace('#^https?://#', '', (string) $hostname);
        $hostname = rtrim((string) $hostname, '/');
        if ($hostname === '' || ! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+$/i', $hostname)) {
            if (method_exists($this, 'toastError')) {
                $this->toastError(__('Hostname does not look valid.'));
            }

            return;
        }

        $backend = EdgeRouter::backendFor($this->site);
        if ($backend === null) {
            if (method_exists($this, 'toastError')) {
                $this->toastError(__('No edge backend available for this site.'));
            }

            return;
        }

        try {
            $backend->attachDomain($this->site->fresh(), $hostname);
        } catch (\Throwable $e) {
            if (method_exists($this, 'toastError')) {
                $this->toastError($e->getMessage());
            }

            return;
        }

        $this->edge_domain_input = '';
        $this->site->refresh();

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Custom domain attached. Configure DNS, then verify when ready.'));
        }
    }

    public function verifyEdgeDomain(string $hostname): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $entry = app(EdgeCustomDomainProvisioner::class)->verify($this->site->fresh(), $hostname);
        $this->site->refresh();

        $status = is_array($entry) ? (string) ($entry['dns_status'] ?? '') : '';
        if ($status === 'ready') {
            if (method_exists($this, 'toastSuccess')) {
                $this->toastSuccess(__('DNS verified — :hostname is live on Edge.', ['hostname' => $hostname]));
            }

            return;
        }

        $error = is_array($entry) ? (string) ($entry['error'] ?? '') : '';
        if (method_exists($this, 'toastError')) {
            $this->toastError($error !== '' ? $error : __('DNS verification failed. Check your CNAME and try again.'));
        }
    }

    public function detachEdgeDomain(string $hostname): void
    {
        if (! $this->site->usesEdgeRuntime()) {
            return;
        }
        $this->authorize('update', $this->site);

        $backend = EdgeRouter::backendFor($this->site);
        if ($backend === null) {
            if (method_exists($this, 'toastError')) {
                $this->toastError(__('No edge backend available for this site.'));
            }

            return;
        }

        try {
            app(EdgeCustomDomainProvisioner::class)->remove($this->site->fresh(), $hostname);
        } catch (\Throwable $e) {
            if (method_exists($this, 'toastError')) {
                $this->toastError($e->getMessage());
            }

            return;
        }

        if (method_exists($this, 'toastSuccess')) {
            $this->toastSuccess(__('Custom domain removed.'));
        }
    }
}
