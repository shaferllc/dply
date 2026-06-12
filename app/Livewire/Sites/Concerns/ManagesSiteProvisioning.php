<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Concerns;

use App\Actions\Edge\RedeployEdgeSite;
use App\Jobs\ApplySiteWebserverConfigJob;
use App\Jobs\InstallServerWebserverJob;
use App\Jobs\IssueSiteSslJob;
use App\Jobs\ProvisionSiteJob;
use App\Jobs\RestartSiteProvisioningJob;
use App\Models\Site;
use App\Models\SiteCertificate;
use App\Services\Certificates\CertificateRepairService;
use App\Services\Deploy\SiteRuntimeActionExecutor;
use App\Services\Edge\EdgeSiteCanceller;
use App\Services\Sites\SiteProvisioner;
use App\Services\Sites\SiteProvisioningCanceller;
use Livewire\Attributes\On;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSiteProvisioning
{
    #[On('site-provisioning-updated')]
    public function refreshProvisioningStatus(string $siteId): void
    {
        if ((string) $this->site->id !== $siteId) {
            return;
        }

        $this->site->refresh();
        $this->syncFormFromSite();
    }

    public function pollProvisioningStatus(): void
    {
        if ($this->site->isReadyForWorkspace()) {
            return;
        }

        $this->site->refresh();
        $this->syncFormFromSite();
    }

    public function shouldAutoReapplyManagedWebserverConfig(): bool
    {
        return $this->server->hostCapabilities()->supportsWebserverProvisioning()
            && ! $this->site->usesFunctionsRuntime()
            && ! $this->site->usesDockerRuntime()
            && ! $this->site->usesKubernetesRuntime();
    }

    public function installNginx(): void
    {
        $this->authorize('update', $this->site);
        if (! $this->server->hostCapabilities()->supportsWebserverProvisioning()) {
            $this->toastError(__('This host runtime does not use managed webserver config.'));

            return;
        }

        ApplySiteWebserverConfigJob::dispatch($this->site->id);
        $this->toastSuccess(__('Webserver config write queued.'));
    }

    public function issueSsl(): void
    {
        $this->authorize('update', $this->site);
        if (! $this->server->hostCapabilities()->supportsWebserverProvisioning()) {
            $this->toastError(__('This host runtime does not issue SSL from the server workspace.'));

            return;
        }

        IssueSiteSslJob::dispatch($this->site->id);

        $org = $this->site->server?->organization;
        if ($org) {
            audit_log($org, auth()->user(), 'site.ssl.issuance_queued', $this->site, null, [
                'primary_hostname' => optional($this->site->primaryDomain)->hostname,
            ]);
        }

        $this->toastSuccess(__('SSL certificate issuance queued.'));
    }

    public function retryProvisioning(SiteProvisioner $siteProvisioner): void
    {
        $this->authorize('update', $this->site);

        $this->site->refresh();

        if ($this->site->isReadyForWorkspace()) {
            $this->toastSuccess(__('This site is already configured.'));

            return;
        }

        if ($this->site->usesEdgeRuntime()) {
            try {
                (new RedeployEdgeSite)->handle($this->site->fresh());
            } catch (\Throwable $e) {
                $this->toastError($e->getMessage());

                return;
            }

            $this->site->refresh();
            $this->toastSuccess(__('Edge build queued again.'));

            return;
        }

        $this->site->status = Site::STATUS_PENDING;
        $this->site->save();

        $siteProvisioner->markQueued($this->site->fresh());
        ProvisionSiteJob::dispatch($this->site->id);

        $this->site->refresh();
        $this->toastSuccess(__('Site provisioning has been queued again.'));
    }

    public function openRestartProvisioningFreshModal(): void
    {
        $this->authorize('update', $this->site);

        if ($this->site->usesEdgeRuntime()) {
            return;
        }

        $this->openConfirmActionModal(
            'restartProvisioningFresh',
            [],
            __('Restart provisioning from scratch?'),
            __('This removes the testing DNS record, any certificates issued so far, and web server configuration written for this site on the server, then runs the full install again. Domains and site settings in Dply are kept.'),
            __('Restart fresh'),
            true,
        );
    }

    public function restartProvisioningFresh(SiteProvisioner $siteProvisioner): void
    {
        $this->authorize('update', $this->site);

        $this->site->refresh();

        if ($this->site->usesEdgeRuntime()) {
            $this->toastError(__('Use Edge build controls to restart an Edge site.'));

            return;
        }

        if ($this->site->isReadyForWorkspace()) {
            $this->toastError(__('This site is already configured. Delete it from site settings if you want to remove it entirely.'));

            return;
        }

        // The actual cleanup runs synchronous SSH (vhost teardown,
        // placeholder index removal, testing-DNS delete) which can take
        // 30–60s and would block the Livewire response, leaving the
        // operator staring at a spinner that never resolves. Queue it
        // instead and write a "restart queued" log line so the journey
        // poll immediately reflects that something happened.
        $siteProvisioner->appendLog(
            $this->site,
            'info',
            'restart',
            'Restart queued. Cleanup and re-provisioning will run in the background.',
        );
        $siteProvisioner->markQueued($this->site);

        RestartSiteProvisioningJob::dispatch((string) $this->site->id);

        $this->site->refresh();
        $this->toastSuccess(__('Restart queued — provisioning will run in the background.'));
    }

    public function openCancelProvisioningModal(): void
    {
        $this->authorize('update', $this->site);

        if ($this->site->usesEdgeRuntime()) {
            $this->openConfirmActionModal(
                'cancelProvisioning',
                [],
                __('Cancel Edge build?'),
                __('This stops the build, removes any partial deployment from the edge network, and deletes the pending site. If you cancel this dialog, the build keeps running.'),
                __('Cancel and remove site'),
                true,
            );

            return;
        }

        $this->openConfirmActionModal(
            'cancelProvisioning',
            [],
            __('Halt provisioning?'),
            __('This stops the install, removes the generated testing DNS record, cleans up any web server config that was written, and deletes the pending site. If you cancel this dialog, provisioning keeps running.'),
            __('Halt and remove site'),
            true,
        );
    }

    public function cancelProvisioning(SiteProvisioningCanceller $canceller, EdgeSiteCanceller $edgeCanceller): void
    {
        $this->authorize('update', $this->site);

        $this->site->refresh();

        if ($this->site->isReadyForWorkspace()) {
            $this->toastError(__('This site is already configured. Delete it from the site actions instead.'));

            return;
        }

        try {
            if ($this->site->usesEdgeRuntime()) {
                $edgeCanceller->cancel($this->site->fresh(['server', 'domains']));
                $this->redirect(route('edge.index'), navigate: true);

                return;
            }

            $canceller->cancel($this->site->fresh(['server', 'domains']));
        } catch (\Throwable $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->redirect(route('sites.create', $this->server), navigate: true);
    }

    public function runRuntimeAction(string $action, SiteRuntimeActionExecutor $executor): void
    {
        $this->authorize('update', $this->site);

        try {
            $result = $executor->run($this->site->fresh(), $action);
            $this->storeRuntimeActionResult($action, $result);
            $this->site->refresh();
            $this->toastSuccess(match ($action) {
                'rebuild' => __('Runtime rebuilt.'),
                'start' => __('Runtime started.'),
                'stop' => __('Runtime stopped.'),
                'restart' => __('Runtime restarted.'),
                'inspect' => __('Docker details refreshed.'),
                'errors' => __('Runtime errors refreshed.'),
                'logs' => __('Runtime logs refreshed.'),
                'destroy' => __('Runtime destroyed.'),
                default => __('Runtime status refreshed.'),
            });
        } catch (\Throwable $e) {
            $this->storeRuntimeActionFailure($action, $e->getMessage());
            $this->site->refresh();
            $this->toastError($e->getMessage());
        }
    }

    /**
     * @param  array{status: string, output: string, publication?: array<string, mixed>, runtime_details?: array<string, mixed>}  $result
     */
    private function storeRuntimeActionResult(string $action, array $result): void
    {
        $site = $this->site->fresh();
        $meta = is_array($site->meta) ? $site->meta : [];
        $runtimeTarget = is_array($meta['runtime_target'] ?? null) ? $meta['runtime_target'] : $site->runtimeTarget();
        $dockerRuntime = is_array($meta['docker_runtime'] ?? null) ? $meta['docker_runtime'] : [];
        $logs = collect($runtimeTarget['logs'] ?? [])
            ->filter(fn (mixed $log): bool => is_array($log))
            ->push([
                'action' => $action,
                'status' => $result['status'],
                'output' => $result['output'],
                'ran_at' => now()->toIso8601String(),
            ])
            ->take(-10)
            ->values()
            ->all();

        $runtimeTargetUpdates = [
            'status' => $result['status'],
            'last_operation' => $action,
            'last_operation_status' => $result['status'],
            'last_operation_at' => now()->toIso8601String(),
            'last_operation_output' => $result['output'],
            'logs' => $logs,
        ];

        if ($action === 'destroy') {
            $runtimeTargetUpdates['publication'] = [];
            $dockerRuntime['runtime_details'] = [];
        } else {
            if (is_array($result['publication'] ?? null)) {
                $runtimeTargetUpdates['publication'] = $result['publication'];
            }

            if (is_array($result['runtime_details'] ?? null)) {
                $dockerRuntime['runtime_details'] = $result['runtime_details'];
            }
        }

        $runtimeTarget = array_merge($runtimeTarget, $runtimeTargetUpdates);

        $meta['runtime_target'] = $runtimeTarget;
        if ($dockerRuntime !== []) {
            $meta['docker_runtime'] = $dockerRuntime;
        }

        $site->forceFill(['meta' => $meta])->save();
    }

    private function storeRuntimeActionFailure(string $action, string $message): void
    {
        $site = $this->site->fresh();
        $meta = is_array($site->meta) ? $site->meta : [];
        $runtimeTarget = is_array($meta['runtime_target'] ?? null) ? $meta['runtime_target'] : $site->runtimeTarget();
        $logs = collect($runtimeTarget['logs'] ?? [])
            ->filter(fn (mixed $log): bool => is_array($log))
            ->push([
                'action' => $action,
                'status' => 'failed',
                'output' => $message,
                'ran_at' => now()->toIso8601String(),
            ])
            ->take(-10)
            ->values()
            ->all();

        $runtimeTarget = array_merge($runtimeTarget, [
            'last_operation' => $action,
            'last_operation_status' => 'failed',
            'last_operation_at' => now()->toIso8601String(),
            'last_operation_output' => $message,
            'logs' => $logs,
        ]);

        $meta['runtime_target'] = $runtimeTarget;
        $site->forceFill(['meta' => $meta])->save();
    }

    public function retryCertificate(string $certificateId, CertificateRepairService $repairService): void
    {
        $this->authorize('update', $this->site);
        $certificate = SiteCertificate::query()
            ->where('site_id', $this->site->id)
            ->findOrFail($certificateId);

        try {
            $repairService->repair($this->site, $certificate, auth()->id());
            $this->site->refresh();
            $this->toastSuccess(__('Certificate repair finished.'));
        } catch (\Throwable $e) {
            $this->site->refresh();
            $this->toastError($e->getMessage());
        }
    }

    public function repairCertificate(string $certificateId, CertificateRepairService $repairService): void
    {
        $this->retryCertificate($certificateId, $repairService);
    }

    /**
     * Retrofit Caddy onto the server when the install profile left it
     * webserver=none (e.g. legacy queue_worker provisions). Dispatches
     * {@see InstallServerWebserverJob}, which SSHes in, installs
     * Caddy, updates server meta, and re-queues provisioning for sites
     * stuck on the headless path.
     */
    public function installServerWebserver(): void
    {
        $this->authorize('update', $this->server);

        if ((string) ($this->server->meta['webserver'] ?? '') !== 'none') {
            $this->toastError(__('Caddy is already installed on this server.'));

            return;
        }

        // Stamp a "pending" flag so a refresh / second click shows the
        // button as in-flight rather than re-queuing the install.
        $meta = is_array($this->server->meta) ? $this->server->meta : [];
        $meta['webserver_install_pending'] = true;
        $this->server->forceFill(['meta' => $meta])->save();

        InstallServerWebserverJob::dispatch($this->server->id, 'caddy');

        $this->toastSuccess(__('Caddy install queued. The page will refresh once the server reports back.'));
    }
}
