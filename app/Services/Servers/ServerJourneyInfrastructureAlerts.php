<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Enums\ServerProvider;
use App\Models\Server;
use App\Modules\Cloud\Services\DigitalOceanService;
use App\Support\Servers\FakeCloudProvision;
use App\Support\Servers\TcpPortProbe;
use Illuminate\Support\Facades\Cache;

/**
 * Cached DigitalOcean + TCP hints for the provision journey page (wire:poll).
 */
final class ServerJourneyInfrastructureAlerts
{
    private const DO_CACHE_TTL_SECONDS = 30;

    private const TCP_CACHE_TTL_SECONDS = 20;

    /**
     * @return array{
     *     digitalocean_gone?: array{headline: string, detail: string},
     *     digitalocean_unknown?: array{headline: string, detail: string},
     *     ssh_unreachable?: array{headline: string, detail: string}
     * }
     */
    /** @return array<string, mixed> */
    public function forServer(Server $server): array
    {
        $server = $server->fresh();
        if ($server === null) {
            return [];
        }

        $alerts = [];

        $doAlert = $this->digitalOceanAlert($server);
        if ($doAlert !== null) {
            if (isset($doAlert['gone'])) {
                $alerts['digitalocean_gone'] = $doAlert['gone'];

                return $alerts;
            }
            if (isset($doAlert['unknown'])) {
                $alerts['digitalocean_unknown'] = $doAlert['unknown'];
            }
        }

        $sshAlert = $this->sshPortAlert($server);
        if ($sshAlert !== null) {
            $alerts['ssh_unreachable'] = $sshAlert;
        }

        return $alerts;
    }

    /**
     * @return array{gone?: array{headline: string, detail: string}, unknown?: array{headline: string, detail: string}}|null
     */
    private function digitalOceanAlert(Server $server): ?array
    {
        if (FakeCloudProvision::isFakeServer($server)) {
            return null;
        }

        if ($server->provider !== ServerProvider::DigitalOcean || ! $server->isVmHost()) {
            return null;
        }

        $pid = trim((string) ($server->provider_id ?? ''));
        if ($pid === '' || ! ctype_digit($pid)) {
            return null;
        }

        $server->loadMissing('providerCredential');

        $cacheKey = 'server.journey.infra.do.'.$server->id;

        return Cache::remember($cacheKey, now()->addSeconds(self::DO_CACHE_TTL_SECONDS), function () use ($server, $pid): ?array {
            $credential = $server->providerCredential;
            if ($credential === null) {
                return [
                    'unknown' => [
                        'headline' => __('Cannot verify DigitalOcean droplet'),
                        'detail' => __('Link a DigitalOcean credential on this organization to check whether the droplet still exists.'),
                    ],
                ];
            }

            try {
                $do = new DigitalOceanService($credential);
                $result = $do->inspectDropletPresence((int) $pid);
            } catch (\Throwable $e) {
                return [
                    'unknown' => [
                        'headline' => __('Could not reach DigitalOcean'),
                        'detail' => $e->getMessage(),
                    ],
                ];
            }

            return match ($result['state']) {
                'gone' => [
                    'gone' => [
                        'headline' => __('Droplet no longer exists in DigitalOcean'),
                        'detail' => __('This droplet was deleted in the DigitalOcean control plane. Remove this server from Dply when you are done, or recreate the VM and reconnect.'),
                    ],
                ],
                'present' => null,
                default => [
                    'unknown' => [
                        'headline' => __('Could not verify droplet status'),
                        'detail' => $result['detail'] ?? __('Unexpected response from DigitalOcean.'),
                    ],
                ],
            };
        });
    }

    /**
     * @return array{headline: string, detail: string}|null
     */
    private function sshPortAlert(Server $server): ?array
    {
        if (FakeCloudProvision::isFakeServer($server)) {
            return null;
        }

        if (! $server->isVmHost()) {
            return null;
        }

        if (! $this->shouldProbeSshPortForAlert($server)) {
            return null;
        }

        $ip = trim((string) ($server->ip_address ?? ''));
        if ($ip === '') {
            return null;
        }

        $port = (int) ($server->ssh_port ?: 22);

        $cacheKey = 'server.journey.infra.tcp.'.$server->id;

        $open = Cache::remember($cacheKey, now()->addSeconds(self::TCP_CACHE_TTL_SECONDS), function () use ($ip, $port): bool {
            return TcpPortProbe::isOpen($ip, $port, 3);
        });

        if ($open) {
            return null;
        }

        return [
            'headline' => __('SSH port not reachable'),
            'detail' => __('We could not open a TCP connection to :endpoint. The host may be down, the droplet removed, or firewalls may block access.', ['endpoint' => $ip.':'.$port]),
        ];
    }

    /**
     * Avoid noisy TCP warnings while the cloud IP or sshd may not exist yet.
     *
     * Only probe once setup is genuinely settled (done/failed). During
     * provisioning AND during setup-running, sshd routinely flaps —
     * cloud-init triggers package installs that restart the service,
     * config edits do daemon-reload, etc. Probing during those windows
     * produces a banner that disappears + reappears every 20 seconds
     * (the TCP probe cache TTL), which reads like dply is broken when
     * really the server is mid-setup. Once setup_status flips to done
     * or failed, sshd is stable and a probe failure means something
     * the operator should see.
     */
    private function shouldProbeSshPortForAlert(Server $server): bool
    {
        if (in_array($server->status, [Server::STATUS_PENDING, Server::STATUS_PROVISIONING], true)) {
            return false;
        }

        // setup_status mid-flight (pending or running) — skip the probe.
        // Only DONE / FAILED are stable enough to surface a real alert.
        if (! in_array($server->setup_status, [Server::SETUP_STATUS_DONE, Server::SETUP_STATUS_FAILED], true)) {
            return false;
        }

        return true;
    }
}
