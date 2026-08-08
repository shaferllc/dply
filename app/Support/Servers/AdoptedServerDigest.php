<?php

namespace App\Support\Servers;

use App\Models\Server;

/**
 * What to say on the fleet list for a server dply adopted rather than built.
 *
 * These hosts never run a provisioning journey, so the "waiting for setup to
 * start" strip is meaningless for them. What an operator wants instead is what
 * dply found already running — read-only, from the inventory probe.
 */
class AdoptedServerDigest
{
    /**
     * @return array{state: string, label: string, detail: string|null}|null
     */
    public static function forServer(Server $server): ?array
    {
        $meta = is_array($server->meta) ? $server->meta : [];

        if (! ($meta['adopted'] ?? false)) {
            return null;
        }

        $found = self::found($meta);

        // The probe has not reported yet — say so rather than showing an empty
        // "found nothing", which reads like a broken box.
        if ($found === []) {
            return [
                'state' => 'scanning',
                'label' => __('Reading what is installed'),
                'detail' => __('dply is scanning this server. Nothing is installed or changed.'),
            ];
        }

        return [
            'state' => 'scanned',
            'label' => __('Adopted'),
            'detail' => implode(' · ', $found),
        ];
    }

    /**
     * Short list of what the inventory probe saw. Keys mirror the blocks
     * {@see ServerInventoryProbeScript} writes into meta.
     *
     * @param  array<string, mixed>  $meta
     * @return list<string>
     */
    private static function found(array $meta): array
    {
        $found = [];

        $nginx = is_array($meta['manage_nginx'] ?? null) ? $meta['manage_nginx'] : [];
        if (($nginx['INSTALLED'] ?? null) === 'yes' || filled($nginx['VERSION'] ?? null)) {
            $sites = (int) ($nginx['SITES_ENABLED_COUNT'] ?? 0);
            $found[] = $sites > 0
                ? trans_choice('nginx · {1} :count vhost|[2,*] nginx · :count vhosts', $sites, ['count' => $sites])
                : 'nginx';
        }

        $php = is_array($meta['manage_php_fpm'] ?? null) ? $meta['manage_php_fpm'] : [];
        $versions = array_values(array_filter(array_map(
            static fn ($v): string => is_array($v) ? (string) ($v['version'] ?? '') : '',
            is_array($php['versions'] ?? null) ? $php['versions'] : []
        )));
        if ($versions !== []) {
            $found[] = 'PHP '.implode(', ', array_slice($versions, 0, 3));
        }

        $mysql = is_array($meta['manage_mysql'] ?? null) ? $meta['manage_mysql'] : [];
        if (($mysql['INSTALLED'] ?? null) === 'yes' || filled($mysql['VERSION'] ?? null)) {
            $found[] = 'MySQL';
        }

        return $found;
    }
}
