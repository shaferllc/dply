<?php

namespace App\Services\Sites;

use App\Events\Sites\SiteProvisioningUpdatedBroadcast;
use App\Models\Site;

class SiteProvisioner
{
    private const LOG_LIMIT = 80;

    public function __construct(
        private readonly TestingHostnameProvisioner $testingHostnameProvisioner,
        private readonly SiteNginxProvisioner $siteNginxProvisioner,
        private readonly SiteCaddyProvisioner $siteCaddyProvisioner,
        private readonly SiteReachabilityChecker $siteReachabilityChecker,
    ) {}

    public function begin(Site $site): void
    {
        $site->loadMissing(['server', 'domains']);

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
            $this->appendLog($site, 'warning', 'provisioning_testing_hostname', 'Testing hostname provisioning was skipped.', [
                'reason' => $testingHostnameMeta['reason'] ?? null,
            ]);
        }

        $this->updateProvisioning($site, [
            'state' => 'writing_site_config',
            'webserver' => $site->webserver(),
            'error' => null,
        ]);

        $this->appendLog($site, 'info', 'writing_site_config', 'Writing web server configuration.', [
            'webserver' => $site->webserver(),
        ]);

        match ($site->webserver()) {
            'nginx' => $this->siteNginxProvisioner->provision($site),
            'caddy' => $this->siteCaddyProvisioner->provision($site),
            'apache' => throw new \RuntimeException('Apache site provisioning is not implemented yet. Use nginx or caddy for now.'),
            default => throw new \RuntimeException('Unsupported webserver ['.$site->webserver().'] for site provisioning.'),
        };

        $site->refresh();

        $this->appendLog($site, 'info', 'writing_site_config', 'Web server configuration written.', [
            'webserver' => $site->webserver(),
        ]);

        $this->updateProvisioning($site, [
            'state' => 'waiting_for_http',
            'webserver' => $site->webserver(),
            'configured_at' => now()->toIso8601String(),
            'error' => null,
        ]);

        $this->appendLog($site, 'info', 'waiting_for_http', 'Beginning hostname reachability checks.');
    }

    /**
     * @return array{ok: bool, hostname: ?string, url: ?string, error: ?string, checked_at: string}
     */
    public function checkReadiness(Site $site): array
    {
        $site->loadMissing(['server', 'domains']);
        $result = $this->siteReachabilityChecker->check($site);

        if ($result['ok']) {
            $site->update([
                'status' => Site::activeStatusForWebserver($site->webserver()),
            ]);

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
        $this->appendLog($site, 'info', 'queued', 'Provisioning job queued.');

        $this->updateProvisioning($site, [
            'state' => 'queued',
            'webserver' => $site->webserver(),
            'queued_at' => now()->toIso8601String(),
            'error' => null,
        ]);
    }

    public function markFailed(Site $site, \Throwable $e): void
    {
        $site->update([
            'status' => Site::STATUS_ERROR,
        ]);

        $this->appendLog($site, 'error', 'failed', 'Provisioning failed.', [
            'error' => $e->getMessage(),
        ]);

        $this->updateProvisioning($site, [
            'state' => 'failed',
            'webserver' => $site->webserver(),
            'failed_at' => now()->toIso8601String(),
            'error' => $e->getMessage(),
        ]);
    }

    public function markTimedOut(Site $site, string $message): void
    {
        $site->update([
            'status' => Site::STATUS_ERROR,
        ]);

        $this->appendLog($site, 'error', 'failed', 'Provisioning timed out before any hostname responded.', [
            'error' => $message,
        ]);

        $this->updateProvisioning($site, [
            'state' => 'failed',
            'webserver' => $site->webserver(),
            'failed_at' => now()->toIso8601String(),
            'error' => $message,
        ]);
    }

    private function updateProvisioning(Site $site, array $payload): void
    {
        $meta = is_array($site->meta) ? $site->meta : [];
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
     * @param  array<string, mixed>  $context
     */
    public function appendLog(Site $site, string $level, string $step, string $message, array $context = []): void
    {
        $meta = is_array($site->meta) ? $site->meta : [];
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
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function filterLogContext(array $context): array
    {
        return collect($context)
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
}
