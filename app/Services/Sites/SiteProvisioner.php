<?php

namespace App\Services\Sites;

use App\Events\Sites\SiteProvisioningUpdatedBroadcast;
use App\Modules\Certificates\Jobs\ExecuteSiteCertificateJob;
use App\Modules\Certificates\Jobs\IssueServerWildcardCertificateJob;
use App\Jobs\ProvisionSiteSystemdUnitsJob;
use App\Models\ServerWildcardCertificate;
use App\Models\Site;
use App\Modules\Certificates\Services\CertificateRequestService;
use App\Modules\Deploy\Services\DeploymentContractBuilder;
use App\Modules\Deploy\Services\DeploymentPreflightValidator;
use App\Modules\Deploy\Services\DeploymentRevisionTracker;
use App\Modules\Deploy\Services\DeploymentValueRedactor;
use Illuminate\Database\QueryException;

class SiteProvisioner
{
    private const LOG_LIMIT = 80;

    public function __construct(
        private readonly TestingHostnameProvisioner $testingHostnameProvisioner,
        private readonly SiteWebserverConfigApplier $webserverConfigApplier,
        private readonly SiteRuntimeProvisionerRegistry $runtimeProvisionerRegistry,
        private readonly SiteReachabilityChecker $siteReachabilityChecker,
        private readonly DigitalOceanFunctionsSiteProvisioner $digitalOceanFunctionsSiteProvisioner,
        private readonly CertificateRequestService $certificateRequestService,
        private readonly DeploymentPreflightValidator $preflightValidator,
        private readonly DeploymentContractBuilder $contractBuilder,
        private readonly DeploymentRevisionTracker $revisionTracker,
        private readonly DeploymentValueRedactor $valueRedactor,
    ) {}

    public function begin(Site $site): void
    {
        $site->loadMissing(['server', 'domains']);
        $this->runPreflight($site);

        if ($site->usesFunctionsRuntime()) {
            $this->appendLog($site, 'info', 'queued', 'Serverless host provisioning worker started.', [
                'runtime_profile' => $site->runtimeProfile(),
                'server_id' => (string) $site->server_id,
            ]);

            $this->updateProvisioning($site, [
                'state' => 'configuring_functions_runtime',
                'webserver' => $site->webserver(),
                'started_at' => now()->toIso8601String(),
                'error' => null,
            ]);

            $this->appendLog($site, 'info', 'configuring_functions_runtime', 'Serverless runtime metadata saved. Waiting for the first deploy to publish a live endpoint.');

            return;
        }

        if ($site->usesDockerRuntime() || $site->usesKubernetesRuntime()) {
            $runtimeProfile = $site->runtimeProfile();
            $state = $site->usesDockerRuntime()
                ? 'configuring_docker_runtime'
                : 'configuring_kubernetes_runtime';
            $runtimeTarget = $site->runtimeTarget();
            $awaitingWebserverForVmDocker = $site->usesVmDockerRuntime();

            $this->appendLog($site, 'info', 'queued', 'Runtime provisioning worker started.', [
                'runtime_profile' => $runtimeProfile,
                'server_id' => (string) $site->server_id,
            ]);

            $this->updateProvisioning($site, [
                'state' => 'preparing_runtime_artifacts',
                'webserver' => $site->webserver(),
                'started_at' => now()->toIso8601String(),
                'error' => null,
            ]);

            $this->appendLog($site, 'info', 'preparing_runtime_artifacts', 'Preparing runtime artifacts for the selected container target.', [
                'runtime_profile' => $runtimeProfile,
                'target_family' => (string) ($runtimeTarget['family'] ?? 'unknown'),
                'target_platform' => (string) ($runtimeTarget['platform'] ?? 'unknown'),
                'target_provider' => (string) ($runtimeTarget['provider'] ?? 'unknown'),
                'target_mode' => (string) ($runtimeTarget['mode'] ?? 'unknown'),
                'app_port' => $site->app_port,
            ]);

            $this->runtimeProvisionerRegistry->for($runtimeProfile)->provision($site);
            $site->refresh();
            $runtimeTarget = $site->runtimeTarget();
            $publication = is_array($runtimeTarget['publication'] ?? null) ? $runtimeTarget['publication'] : [];
            $dockerRuntime = is_array($site->meta['docker_runtime'] ?? null) ? $site->meta['docker_runtime'] : [];
            $kubernetesRuntime = is_array($site->meta['kubernetes_runtime'] ?? null) ? $site->meta['kubernetes_runtime'] : [];

            $this->appendLog($site, 'info', $state, 'Runtime artifact generation finished.', [
                'runtime_profile' => $runtimeProfile,
                'compose_generated' => array_key_exists('compose_yaml', $dockerRuntime),
                'dockerfile_generated' => array_key_exists('dockerfile', $dockerRuntime),
                'manifest_generated' => array_key_exists('manifest_yaml', $kubernetesRuntime),
                'workspace_path' => $dockerRuntime['workspace_path'] ?? $kubernetesRuntime['workspace_path'] ?? null,
            ]);

            $this->updateProvisioning($site, [
                'state' => 'configuring_publication',
                'webserver' => $site->webserver(),
                'error' => null,
            ]);

            $this->appendLog($site, 'info', 'configuring_publication', 'Publication target prepared for the first deploy.', [
                'publication_kind' => (string) ($publication['kind'] ?? 'unknown'),
                'publication_status' => (string) ($publication['status'] ?? 'pending'),
                'publication_hostname' => (string) ($publication['hostname'] ?? ''),
                'published_url' => (string) ($publication['url'] ?? ''),
                'published_port' => $publication['port'] ?? null,
                'dns_provider' => (string) ($publication['dns_provider'] ?? ''),
            ]);

            $this->appendLog($site, 'info', $state, 'Runtime deployment artifacts prepared. Waiting for the first deploy to publish the workload.', [
                'runtime_profile' => $runtimeProfile,
            ]);
            $this->revisionTracker->markApplied($site->fresh(), $this->contractBuilder->build($site->fresh())->revision(), 'runtime');

            if (! $awaitingWebserverForVmDocker) {
                return;
            }
        }

        // Headless sites (webserver=none, e.g. a worker host) run deployed code
        // with no HTTP front — skip testing hostname, vhost config, and HTTP
        // reachability. The site is "active" once the first deploy lands the
        // code; it still uses the same deploy pipeline as any other site.
        if ($site->isHeadless()) {
            $this->appendLog($site, 'info', 'queued', 'Headless provisioning worker started — no web server to configure.', [
                'server_id' => (string) $site->server_id,
            ]);

            $site->update(['status' => Site::activeStatusForWebserver('none')]);

            $this->updateProvisioning($site, [
                'state' => 'ready',
                'webserver' => 'none',
                'started_at' => now()->toIso8601String(),
                'configured_at' => now()->toIso8601String(),
                'checked_at' => now()->toIso8601String(),
                'host_checks' => [],
                'error' => null,
            ]);

            $this->appendLog($site, 'info', 'ready', 'Headless site is ready. Run a deploy to install the code.');
            $this->revisionTracker->markApplied($site->fresh(), $this->contractBuilder->build($site->fresh())->revision(), 'runtime');

            return;
        }

        $this->appendLog($site, 'info', 'queued', 'Provisioning worker started.', [
            'webserver' => $site->webserver(),
            'server_id' => (string) $site->server_id,
        ]);

        $this->updateProvisioning($site, [
            'state' => 'provisioning_testing_hostname',
            'webserver' => $site->webserver(),
            'started_at' => now()->toIso8601String(),
            'error' => null,
        ]);

        $this->appendLog($site, 'info', 'provisioning_testing_hostname', 'Assigning testing hostname.');
        $this->testingHostnameProvisioner->provision($site);
        $site->refresh();

        $testingHostnameMeta = is_array($site->meta['testing_hostname'] ?? null) ? $site->meta['testing_hostname'] : [];
        $testingHostnameStatus = $testingHostnameMeta['status'] ?? null;

        if ($testingHostnameStatus === 'ready') {
            $this->appendLog($site, 'info', 'provisioning_testing_hostname', 'Testing hostname assigned.', [
                'hostname' => $testingHostnameMeta['hostname'] ?? null,
                'zone' => $testingHostnameMeta['zone'] ?? null,
                'record_name' => $testingHostnameMeta['record_name'] ?? null,
                'record_type' => $testingHostnameMeta['record_type'] ?? null,
                'record_data' => $testingHostnameMeta['record_data'] ?? null,
            ]);
        } elseif ($testingHostnameStatus === 'failed') {
            $this->appendLog($site, 'error', 'provisioning_testing_hostname', 'Testing hostname provisioning failed.', [
                'hostname' => $testingHostnameMeta['hostname'] ?? null,
                'zone' => $testingHostnameMeta['zone'] ?? null,
                'record_name' => $testingHostnameMeta['record_name'] ?? null,
                'error' => $testingHostnameMeta['error'] ?? null,
            ]);
        } else {
            $this->appendLog($site, 'error', 'provisioning_testing_hostname', 'Testing hostname provisioning was skipped.', [
                'reason' => $testingHostnameMeta['reason'] ?? null,
            ]);
        }

        if ($testingHostnameStatus !== 'ready') {
            $reason = (string) ($testingHostnameMeta['reason'] ?? 'unknown');
            $detail = (string) ($testingHostnameMeta['error'] ?? '');

            throw new \RuntimeException(match ($reason) {
                'disabled' => 'Testing hostname creation is required before provisioning can continue. Enable DigitalOcean testing hostnames and configure at least one testing domain.',
                'missing_server_ip' => 'Testing hostname creation requires a server IP address before provisioning can continue.',
                default => $detail !== ''
                    ? 'Testing hostname creation failed before provisioning could continue: '.$detail
                    : 'Testing hostname creation must succeed before provisioning can continue.',
            });
        }

        // Write the vhost and enter reachability — but only once any required
        // per-server wildcard TLS cert is installed, so the :443 block is present
        // from the very first response and the site is never published HTTP-only.
        $this->ensureWebserverConfigForReachability($site);
    }

    /**
     * Write the site's webserver config and move into the HTTP-reachability
     * phase — but gate the write on the per-server wildcard TLS certificate for
     * the site's managed testing zone (e.g. *.on-dply.com). Returns true once
     * the vhost is written (proceed to reachability checks), or false while the
     * wildcard is still being issued (the provisioning loop retries). For sites
     * that don't need a wildcard (custom-domain-only, Caddy auto-HTTPS boxes,
     * non-webserver runtimes) the write happens immediately.
     *
     * Idempotent: once the vhost is written it is not rewritten here.
     */
    public function ensureWebserverConfigForReachability(Site $site): bool
    {
        $provisioning = $site->provisioningMeta();
        if (! empty($provisioning['vhost_written_at'])) {
            return true;
        }

        if ($this->requiresServerWildcard($site) && ! $this->ensureServerWildcard($site)) {
            return false;
        }

        $this->updateProvisioning($site, [
            'state' => 'writing_site_config',
            'webserver' => $site->webserver(),
            'error' => null,
        ]);

        $this->appendLog($site, 'info', 'writing_site_config', 'Writing web server configuration.', [
            'webserver' => $site->webserver(),
        ]);

        $this->webserverConfigApplier->apply($site);

        $site->refresh();

        $this->appendLog($site, 'info', 'writing_site_config', 'Web server configuration written.', [
            'webserver' => $site->webserver(),
        ]);
        $this->revisionTracker->markApplied($site->fresh(), $this->contractBuilder->build($site->fresh())->revision(), 'runtime');

        $this->updateProvisioning($site, [
            'state' => 'waiting_for_http',
            'webserver' => $site->webserver(),
            'configured_at' => now()->toIso8601String(),
            'vhost_written_at' => now()->toIso8601String(),
            'error' => null,
        ]);

        $this->appendLog($site, 'info', 'waiting_for_http', 'Beginning hostname reachability checks.');

        return true;
    }

    /**
     * Whether this site's testing hostname is secured by a shared per-server
     * wildcard cert (certbot-managed webservers) rather than a per-host cert or
     * Caddy's own automatic HTTPS.
     */
    private function requiresServerWildcard(Site $site): bool
    {
        if (! (bool) config('sites.wildcard_testing_ssl', true)) {
            return false;
        }

        if ($site->testingZone() === null || $site->server_id === null) {
            return false;
        }

        $webserver = $site->webserver();

        // Caddy with no edge proxy obtains and renews its own certificate on
        // demand — there is no on-disk wildcard for it to reference.
        if ($webserver === 'caddy' && ! ($site->server?->hasEdgeProxy() ?? false)) {
            return false;
        }

        return in_array($webserver, ['nginx', 'caddy', 'openlitespeed', 'apache'], true);
    }

    /**
     * Ensure the per-(server, zone) wildcard cert exists and is being issued.
     * Returns true once it is installed (vhost may be written), false while
     * issuance is still pending/in-flight.
     */
    private function ensureServerWildcard(Site $site): bool
    {
        $zone = $site->testingZone();
        if ($zone === null) {
            return true;
        }

        $routing = $this->testingHostnameProvisioner->testingDnsRoutingForSite($site);
        $credentialId = $routing['credential']?->id;

        try {
            $wildcard = ServerWildcardCertificate::query()->firstOrCreate(
                ['server_id' => $site->server_id, 'zone' => $zone],
                [
                    'provider' => $routing['provider'],
                    'provider_credential_id' => $credentialId,
                    'status' => ServerWildcardCertificate::STATUS_PENDING,
                    'live_directory' => $zone,
                ],
            );
        } catch (QueryException) {
            // Lost the (server_id, zone) unique-index race to a sibling site
            // provisioning concurrently — the row now exists, so reuse it.
            $wildcard = ServerWildcardCertificate::query()
                ->where('server_id', $site->server_id)
                ->where('zone', $zone)
                ->firstOrFail();
        }

        // Keep the controlling provider/credential current (e.g. the org
        // connected a DNS credential after the row was first created).
        if ($wildcard->provider !== $routing['provider'] || $wildcard->provider_credential_id !== $credentialId) {
            $wildcard->forceFill([
                'provider' => $routing['provider'],
                'provider_credential_id' => $credentialId,
            ])->save();
        }

        if ($wildcard->isInstalled()) {
            return true;
        }

        $this->updateProvisioning($site, [
            'state' => 'waiting_for_wildcard_tls',
            'webserver' => $site->webserver(),
            'error' => null,
        ]);

        // Dispatch only when idle (pending/failed) — an in-flight 'issuing' run
        // holds the lock, so re-dispatching every probe would just no-op.
        if (in_array($wildcard->status, [
            ServerWildcardCertificate::STATUS_PENDING,
            ServerWildcardCertificate::STATUS_FAILED,
        ], true)) {
            $this->appendLog($site, 'info', 'waiting_for_wildcard_tls', 'Issuing wildcard TLS certificate for the testing zone.', [
                'zone' => $zone,
                'provider' => $routing['provider'],
            ]);
            IssueServerWildcardCertificateJob::dispatch((string) $site->server_id, $zone);
        }

        return false;
    }

    /**
     * @return array{ok: bool, hostname: ?string, url: ?string, error: ?string, checked_at: string}
     */
    /** @return array<string, mixed> */
    public function checkReadiness(Site $site): array
    {
        $site->loadMissing(['server', 'domains']);

        if ($site->usesFunctionsRuntime()) {
            $result = $this->digitalOceanFunctionsSiteProvisioner->readyResult($site);
            $site->update([
                'status' => Site::STATUS_FUNCTIONS_CONFIGURED,
            ]);

            $this->appendLog($site, 'info', 'awaiting_first_deploy', 'Serverless host is configured. Run the first deploy to publish a live endpoint.', [
                'hostname' => $result['hostname'],
                'url' => $result['url'],
            ]);

            $this->updateProvisioning($site, [
                'state' => 'awaiting_first_deploy',
                'webserver' => $site->webserver(),
                'ready_hostname' => $result['hostname'],
                'ready_url' => $result['url'],
                'checked_at' => $result['checked_at'],
                'host_checks' => [],
                'error' => null,
            ]);

            return $result;
        }

        if ($site->usesDockerRuntime() || $site->usesKubernetesRuntime()) {
            $site->refresh();
            $result = $this->runtimeProvisionerRegistry->for($site->runtimeProfile())->readyResult($site);
            $configuredStatus = $site->usesDockerRuntime()
                ? Site::STATUS_DOCKER_CONFIGURED
                : Site::STATUS_KUBERNETES_CONFIGURED;

            if ($result['ok']) {
                $site->update([
                    'status' => $configuredStatus,
                ]);
            }

            $message = $result['ok']
                ? 'Runtime target is configured. Run the first deploy to publish a live workload.'
                : 'Runtime target metadata is prepared. Waiting for the publication endpoint to become reachable.';

            $this->appendLog($site, 'info', 'awaiting_first_deploy', $message, [
                'hostname' => $result['hostname'],
                'url' => $result['url'],
            ]);

            $this->updateProvisioning($site, [
                'state' => 'awaiting_first_deploy',
                'webserver' => $site->webserver(),
                'ready_hostname' => $result['hostname'],
                'ready_url' => $result['url'],
                'checked_at' => $result['checked_at'],
                'host_checks' => [],
                'error' => null,
            ]);

            return $result;
        }

        $result = $this->siteReachabilityChecker->check($site);

        if ($result['ok']) {
            $site->update([
                'status' => Site::activeStatusForWebserver($site->webserver()),
            ]);

            // For non-PHP/static sites with a start_command, dispatch the
            // systemd-unit installer so the runtime process NGINX now
            // proxies to actually exists. Fire-and-forget; failures land
            // in the worker log but don't roll back the activation.
            if ($site->start_command !== null && $site->start_command !== ''
                && ! in_array($site->runtimeKey(), ['php', 'static', null], true)) {
                ProvisionSiteSystemdUnitsJob::dispatch($site->id);
            }

            if ($previewDomain = $site->primaryPreviewDomain()) {
                $previewDomain->update([
                    'dns_status' => 'ready',
                    'last_dns_checked_at' => now(),
                ]);
            }

            $this->appendLog($site, 'info', 'waiting_for_http', 'Hostname responded successfully.', [
                'hostname' => $result['hostname'],
                'url' => $result['url'],
            ]);

            $this->updateProvisioning($site, [
                'state' => 'ready',
                'webserver' => $site->webserver(),
                'ready_hostname' => $result['hostname'],
                'ready_url' => $result['url'],
                'checked_at' => $result['checked_at'],
                'host_checks' => $result['checks'],
                'error' => null,
            ]);

            $previewHostname = $site->primaryPreviewDomain()?->hostname;
            if ($site->isCoveredByServerWildcard()) {
                // TLS already comes from the shared per-server wildcard — skip
                // per-host Let's Encrypt issuance (which would burn the
                // 50-cert/registered-domain/week limit on the shared zone).
                if ($site->ssl_status !== Site::SSL_ACTIVE) {
                    $site->update([
                        'ssl_status' => Site::SSL_ACTIVE,
                        'ssl_installed_at' => $site->ssl_installed_at ?? now(),
                    ]);
                }
                $this->appendLog($site, 'info', 'ready', 'Preview hostname secured by the shared wildcard certificate.', [
                    'preview_hostname' => $previewHostname,
                ]);
            } elseif ($this->previewHostnameReachableForAutoSsl($site, $result)) {
                $this->queueAutomaticPreviewSsl(
                    $site,
                    'ready',
                    'Queued automatic preview SSL after preview hostname became reachable.',
                    [
                        'hostname' => $previewHostname,
                        'matched_hostname' => $result['hostname'],
                    ],
                );
            } elseif ($previewHostname !== null && $previewHostname !== '') {
                $this->appendLog($site, 'info', 'ready', 'Site is reachable but the preview hostname is not ready for automatic SSL yet.', [
                    'preview_hostname' => $previewHostname,
                    'matched_hostname' => $result['hostname'],
                ]);
            }

            return $result;
        }

        $this->appendLog($site, 'warning', 'waiting_for_http', 'Reachability check did not pass yet.', [
            'hostname' => $result['hostname'],
            'url' => $result['url'],
            'error' => $result['error'],
            'checks' => $result['checks'] ?? [],
        ]);

        $this->updateProvisioning($site, [
            'state' => 'waiting_for_http',
            'webserver' => $site->webserver(),
            'checked_at' => $result['checked_at'],
            'last_checked_hostname' => $result['hostname'],
            'last_checked_url' => $result['url'],
            'host_checks' => $result['checks'],
            'error' => $result['error'],
        ]);

        return $result;
    }

    public function markQueued(Site $site): void
    {
        // First-ever provision (no prior state): freeze a domain-based vhost
        // basename so the on-disk config is easy to find by hostname. Skipped on
        // re-provision — a site provisioned before this existed keeps its legacy
        // `dply-<id>-<slug>` filename rather than orphaning it for a renamed one.
        if ($site->provisioningState() === null) {
            $site->assignWebserverConfigBasename();
        }

        $this->appendLog($site, 'info', 'queued', 'Provisioning job queued.');

        $this->updateProvisioning($site, [
            'state' => 'queued',
            'webserver' => $site->webserver(),
            'queued_at' => now()->toIso8601String(),
            // Clear the deferred-vhost guard so a re-provision re-evaluates the
            // wildcard TLS gate and rewrites the web server config from scratch.
            'vhost_written_at' => null,
            'error' => null,
        ]);
    }

    public function markFailed(Site $site, \Throwable $e): void
    {
        $site->update([
            'status' => Site::STATUS_ERROR,
        ]);

        $this->appendLog($site, 'error', 'failed', 'Provisioning failed.', [
            'error' => $this->valueRedactor->redactMessage($e->getMessage()),
        ]);

        $this->updateProvisioning($site, [
            'state' => 'failed',
            'webserver' => $site->webserver(),
            'failed_at' => now()->toIso8601String(),
            'error' => $this->valueRedactor->redactMessage($e->getMessage()),
        ]);
    }

    public function markTimedOut(Site $site, string $message): void
    {
        $site->update([
            'status' => Site::STATUS_ERROR,
        ]);

        $this->appendLog($site, 'error', 'failed', 'Provisioning timed out before any hostname responded.', [
            'error' => $this->valueRedactor->redactMessage($message),
        ]);

        $this->updateProvisioning($site, [
            'state' => 'failed',
            'webserver' => $site->webserver(),
            'failed_at' => now()->toIso8601String(),
            'error' => $this->valueRedactor->redactMessage($message),
        ]);
    }

    /**
     * @param  array<string, mixed> $payload
     */
    private function updateProvisioning(Site $site, array $payload): void
    {
        $meta = ($site->meta );
        $existing = $site->provisioningMeta();
        $meta['provisioning'] = array_merge($existing, $payload);

        $site->forceFill(['meta' => $meta])->save();
        $site->setAttribute('meta', $meta);
        $site->refresh();

        if ($site->server_id) {
            broadcast(new SiteProvisioningUpdatedBroadcast(
                serverId: (string) $site->server_id,
                siteId: (string) $site->id,
                status: (string) $site->status,
                provisioningState: $site->provisioningState(),
            ));
        }
    }

    /**
     * @param  array<string, mixed> $context
     */
    public function appendLog(Site $site, string $level, string $step, string $message, array $context = []): void
    {
        $meta = ($site->meta );
        $existing = $site->provisioningMeta();
        $log = $existing['log'] ?? [];
        $log = is_array($log) ? $log : [];
        $log[] = [
            'at' => now()->toIso8601String(),
            'level' => $level,
            'step' => $step,
            'message' => $message,
            'context' => $this->filterLogContext($context),
        ];

        $existing['log'] = array_slice($log, -1 * self::LOG_LIMIT);
        $meta['provisioning'] = $existing;

        $site->forceFill(['meta' => $meta])->save();
        $site->setAttribute('meta', $meta);
        $site->refresh();
    }

    /**
     * @param  array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function filterLogContext(array $context): array
    {
        return collect($this->valueRedactor->redactContext($context))
            ->reject(function (mixed $value): bool {
                if ($value === null || $value === '') {
                    return true;
                }

                if (is_array($value) && $value === []) {
                    return true;
                }

                return false;
            })
            ->all();
    }

    /**
     * @param  array{ok: bool, hostname: ?string, url: ?string, error: ?string, checked_at: string, checks?: list<array{hostname: string, url: string, ok: bool, error: ?string}>}  $reachability
     */
    private function previewHostnameReachableForAutoSsl(Site $site, array $reachability): bool
    {
        $previewDomain = $site->primaryPreviewDomain();
        if ($previewDomain === null || ! $previewDomain->auto_ssl) {
            return false;
        }

        $previewHostname = trim((string) $previewDomain->hostname);
        if ($previewHostname === '') {
            return false;
        }

        if (($reachability['hostname'] ?? null) === $previewHostname) {
            return true;
        }

        foreach ($reachability['checks'] ?? [] as $check) {
            if (($check['hostname']) === $previewHostname && ($check['ok'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed> $context
     */
    private function queueAutomaticPreviewSsl(Site $site, string $step, string $message, array $context = []): void
    {
        $previewDomain = $site->fresh(['previewDomains'])->primaryPreviewDomain();
        if ($previewDomain === null || ! $previewDomain->auto_ssl || $previewDomain->hostname === '') {
            return;
        }

        $certificate = $this->certificateRequestService->queuePrimaryPreviewAutoSsl($site->fresh(['previewDomains']));
        if ($certificate === null) {
            return;
        }

        if ($site->ssl_status === Site::SSL_NONE) {
            $site->update(['ssl_status' => Site::SSL_PENDING]);
        }

        $this->appendLog($site, 'info', $step, $message, array_merge($context, [
            'hostname' => $previewDomain->hostname,
            'certificate_id' => $certificate->id,
        ]));
        ExecuteSiteCertificateJob::dispatch($certificate->id);
    }

    private function runPreflight(Site $site): void
    {
        $result = $this->preflightValidator->validate($site);

        foreach ($result['warnings'] as $warning) {
            $this->appendLog($site, 'warning', 'preflight', $warning);
        }

        if ($result['ok']) {
            $this->appendLog($site, 'info', 'preflight', 'Deployment preflight checks passed.');

            return;
        }

        foreach ($result['errors'] as $error) {
            $this->appendLog($site, 'error', 'preflight', $error);
        }

        throw new \RuntimeException(implode(' ', $result['errors']));
    }
}
