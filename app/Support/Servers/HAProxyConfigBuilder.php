<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Models\LoadBalancer;

/**
 * Generates HAProxy configuration files for dply-managed software load balancers.
 *
 * Each load balancer gets a named frontend/backend pair written to
 * /etc/haproxy/conf.d/dply-{lb-id}.cfg. The main haproxy.cfg includes
 * that directory via `includesdir /etc/haproxy/conf.d`.
 */
final class HAProxyConfigBuilder
{
    /**
     * Bash script that writes the HAProxy config file for a load balancer
     * and reloads the service. Idempotent — safe to re-run when targets change.
     *
     * @param  array<string, mixed> $backends
     * @param  array<string, mixed> $services
     */
    public static function applyScript(
        LoadBalancer $lb,
        array $backends,
        array $services,
    ): string {
        $slug = preg_replace('/[^a-zA-Z0-9_-]/', '_', $lb->name);
        $confPath = '/etc/haproxy/conf.d/dply-'.$slug.'.cfg';
        $algorithm = $lb->algorithm === 'least_connections' ? 'leastconn' : 'roundrobin';

        $config = self::buildConfig($slug, $algorithm, $backends, $services);
        $escapedConfig = escapeshellarg($config);

        return <<<BASH
set -e
mkdir -p /etc/haproxy/conf.d

# Ensure haproxy.cfg includes the conf.d directory.
if ! grep -q 'conf.d' /etc/haproxy/haproxy.cfg 2>/dev/null; then
  echo "" >> /etc/haproxy/haproxy.cfg
  echo "includesdir /etc/haproxy/conf.d" >> /etc/haproxy/haproxy.cfg
fi

cat > {$confPath} <<'HAPROXYCFG'
{$config}
HAPROXYCFG

# Validate before reload.
haproxy -c -f /etc/haproxy/haproxy.cfg -f {$confPath}
systemctl reload haproxy || systemctl restart haproxy
echo "haproxy_config_applied"
BASH;
    }

    /**
     * Bash script that removes a load balancer's config file and reloads HAProxy.
     */
    public static function removeScript(LoadBalancer $lb): string
    {
        $slug = preg_replace('/[^a-zA-Z0-9_-]/', '_', $lb->name);
        $confPath = '/etc/haproxy/conf.d/dply-'.$slug.'.cfg';

        return <<<BASH
set -e
rm -f {$confPath}
systemctl reload haproxy || systemctl restart haproxy
echo "haproxy_config_removed"
BASH;
    }

    /**
     * @param  array<string, mixed> $backends
     * @param  array<string, mixed> $services
     */
    private static function buildConfig(
        string $slug,
        string $algorithm,
        array $backends,
        array $services,
    ): string {
        $lines = [];

        foreach ($services as $i => $svc) {
            $protocol = in_array($svc['protocol'], ['http', 'https'], true) ? 'http' : 'tcp';
            $backendName = "dply_{$slug}_be_{$i}";
            $frontendName = "dply_{$slug}_fe_{$i}";

            // Frontend
            $lines[] = "frontend {$frontendName}";
            $lines[] = "    bind *:{$svc['listen_port']}";
            $lines[] = "    mode {$protocol}";
            if ($protocol === 'http') {
                $lines[] = '    option forwardfor';
                $lines[] = '    http-request set-header X-Forwarded-Proto http';
            }
            $lines[] = "    default_backend {$backendName}";
            $lines[] = '';

            // Backend
            $lines[] = "backend {$backendName}";
            $lines[] = "    mode {$protocol}";
            $lines[] = "    balance {$algorithm}";
            if ($protocol === 'http') {
                $lines[] = '    option httpchk GET /';
                $lines[] = '    http-check expect status 200-399';
            } else {
                $lines[] = '    option tcp-check';
            }
            foreach ($backends as $j => $be) {
                $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $be['name']).'_'.$j;
                $line = "    server {$safeName} {$be['ip']}:{$svc['destination_port']} check inter 10s fall 3 rise 2";
                // Per-backend weight drives canary traffic shifting; `disabled`
                // pulls a backend from rotation for a rolling step (drain).
                if (isset($be['weight'])) {
                    $line .= ' weight '.(int) $be['weight'];
                }
                if (! empty($be['disabled'])) {
                    $line .= ' disabled';
                }
                $lines[] = $line;
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }
}
