<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\Server;
use App\Models\ServerCacheService;
use App\Models\ServerDatabase;
use App\Models\ServerDatabaseEngine;
use App\Services\Servers\ServerProvisionCommandBuilder;

/**
 * Builds the Hetzner Cloud Firewall inbound rule set for a server, mirroring the
 * on-box UFW exposure that {@see ServerProvisionCommandBuilder}
 * applies. The cloud firewall sits at Hetzner's edge — without it, a project that
 * has any Cloud Firewall attached drops inbound 22 before packets ever reach UFW,
 * leaving the box unreachable.
 *
 * Hetzner rule shape: {direction, protocol, port?, source_ips[]} (port omitted for icmp).
 */
final class HetznerCloudFirewallRules
{
    /**
     * @return list<array<string,mixed>>
     */
    public static function forServer(Server $server): array
    {
        $anywhere = ['0.0.0.0/0', '::/0'];

        // SSH + ICMP are always allowed so dply can reach, provision, and
        // health-check the box. Mirrors `ufw allow OpenSSH`.
        $rules = [
            self::tcp('22', $anywhere),
            ['direction' => 'in', 'protocol' => 'icmp', 'source_ips' => $anywhere],
        ];

        $meta = is_array($server->meta) ? $server->meta : [];

        // App servers expose HTTP/HTTPS. Mirrors `ufw allow 80/tcp` + `443/tcp`.
        $webserver = trim((string) ($meta['webserver'] ?? 'none'));
        if ($webserver !== '' && $webserver !== 'none') {
            $rules[] = self::tcp('80', $anywhere);
            $rules[] = self::tcp('443', $anywhere);
        }

        // Dedicated cache servers expose their engine port ONLY to the operator's
        // configured CIDR. Mirrors DedicatedCacheServerProvisionConfig::ufwAllowLines().
        $engine = trim((string) ($meta['cache_service'] ?? ''));
        if ($engine !== '' && $engine !== 'none') {
            $config = DedicatedCacheServerProvisionConfig::fromServer($server, $engine);
            if ($config->remoteAccess
                && DedicatedCacheServerProvisionConfig::engineSupportsRemoteAccess($config->engine)
                && DedicatedCacheServerProvisionConfig::isAllowedSourceCidr($config->allowedFrom)
            ) {
                $rules[] = self::tcp(
                    (string) ServerCacheService::defaultPortFor($config->engine),
                    [$config->allowedFrom],
                );
            }
        }

        // Database engines with at least one database that has remote_access enabled.
        // Open the engine port to any CIDR that any of its databases is exposed to.
        // This mirrors what ToggleDatabaseNetworkingJob writes to UFW.
        $exposedEngines = ServerDatabase::query()
            ->where('server_id', $server->id)
            ->where('remote_access', true)
            ->whereNotNull('allowed_from')
            ->get()
            ->groupBy('engine');

        foreach ($exposedEngines as $engineName => $dbs) {
            $engineRow = ServerDatabaseEngine::query()
                ->where('server_id', $server->id)
                ->where('engine', $engineName)
                ->first();

            $port = $engineRow->port ?? ServerDatabaseEngine::defaultPortFor($engineName);

            // Collect the unique CIDRs from the exposed databases.
            $cidrs = $dbs->pluck('allowed_from')->filter()->unique()->values()->all();
            if (empty($cidrs)) {
                $cidrs = ['0.0.0.0/0', '::/0'];
            }

            $rules[] = self::tcp((string) $port, $cidrs);
        }

        return $rules;
    }

    /**
     * @param  list<string> $sourceIps
     * @return array<string,mixed>
     */
    private static function tcp(string $port, array $sourceIps): array
    {
        return [
            'direction' => 'in',
            'protocol' => 'tcp',
            'port' => $port,
            'source_ips' => $sourceIps,
        ];
    }
}
