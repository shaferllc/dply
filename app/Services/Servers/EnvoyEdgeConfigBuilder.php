<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Site;
use Illuminate\Database\Eloquent\Collection;

/**
 * Renders the monolithic `/etc/envoy/envoy.yaml` for a dply-edged
 * Envoy server. Like HAProxy, the whole config is generated in one shot
 * covering all sites + the bind port. The switch flow writes :8080 during
 * validate/provision and :80 at cutover.
 *
 * Architecture: one HTTP listener bound to $listenPort, one virtual_host
 * per site keyed by Host header domains, one STATIC cluster per site
 * pointing at the per-site Caddy upstream on its ephemeral high port,
 * and a catch-all virtual_host returning 503 for unmatched hosts.
 */
class EnvoyEdgeConfigBuilder
{
    /**
     * @param  Collection<int, Site>  $sites
     * @param  callable(Site): int  $backendPortFor  Resolves the per-site Caddy upstream port.
     */
    public function build(Collection $sites, int $listenPort, callable $backendPortFor): string
    {
        $virtualHosts = [];
        $clusters = [];

        foreach ($sites as $site) {
            $basename = $this->basenameFor($site);
            $clusterName = 'cluster_'.$this->sanitize($basename);
            $vhostName = 'vhost_'.$this->sanitize($basename);
            $port = (int) $backendPortFor($site);
            $hostnames = $this->hostnamesFor($site);
            if ($hostnames === []) {
                continue;
            }

            $domains = [];
            foreach ($hostnames as $h) {
                $domains[] = $h;
                $domains[] = $h.':'.$listenPort;
            }

            $virtualHosts[] = $this->renderVirtualHost($vhostName, $domains, $clusterName);
            $clusters[] = $this->renderCluster($clusterName, $port);
        }

        $virtualHosts[] = $this->renderUnmatchedVirtualHost();
        $virtualHostBlock = implode("\n", $virtualHosts);
        $clusterBlock = $clusters !== [] ? implode("\n", $clusters)."\n" : '';

        $config = <<<YAML
# Managed by Dply — do NOT hand-edit. Regenerated on every webserver switch.
admin:
  address:
    socket_address:
      address: 127.0.0.1
      port_value: 9901

static_resources:
  listeners:
  - name: dply_http
    address:
      socket_address:
        address: 0.0.0.0
        port_value: {$listenPort}
    filter_chains:
    - filters:
      - name: envoy.filters.network.http_connection_manager
        typed_config:
          "@type": type.googleapis.com/envoy.extensions.filters.network.http_connection_manager.v3.HttpConnectionManager
          stat_prefix: dply_ingress
          route_config:
            name: dply_routes
            virtual_hosts:
{$virtualHostBlock}
          http_filters:
          - name: envoy.filters.http.router
            typed_config:
              "@type": type.googleapis.com/envoy.extensions.filters.http.router.v3.Router
  clusters:
{$clusterBlock}
YAML;

        return rtrim($config)."\n";
    }

    /**
     * @param  list<string>  $domains
     */
    private function renderVirtualHost(string $name, array $domains, string $clusterName): string
    {
        $domainLines = implode("\n", array_map(
            fn (string $d): string => '              - '.json_encode($d, JSON_UNESCAPED_SLASHES),
            $domains,
        ));

        return <<<YAML
            - name: {$name}
              domains:
{$domainLines}
              routes:
              - match:
                  prefix: "/"
                route:
                  cluster: {$clusterName}
YAML;
    }

    private function renderUnmatchedVirtualHost(): string
    {
        return <<<'YAML'
            - name: dply_unmatched
              domains:
              - "*"
              routes:
              - match:
                  prefix: "/"
                direct_response:
                  status: 503
                  body:
                    inline_string: "dply: no backend matches this host\n"
YAML;
    }

    private function renderCluster(string $clusterName, int $port): string
    {
        return <<<YAML
  - name: {$clusterName}
    connect_timeout: 5s
    type: STATIC
    lb_policy: ROUND_ROBIN
    load_assignment:
      cluster_name: {$clusterName}
      endpoints:
      - lb_endpoints:
        - endpoint:
            address:
              socket_address:
                address: 127.0.0.1
                port_value: {$port}
YAML;
    }

    /**
     * @return list<string>
     */
    private function hostnamesFor(Site $site): array
    {
        return collect($site->webserverHostnames())
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function basenameFor(Site $site): string
    {
        return method_exists($site, 'webserverConfigBasename')
            ? (string) $site->webserverConfigBasename()
            : (string) $site->slug;
    }

    /**
     * Envoy cluster/virtual_host names accept [a-zA-Z0-9_-.].
     */
    private function sanitize(string $s): string
    {
        return preg_replace('/[^A-Za-z0-9_]/', '_', $s) ?? 'site';
    }
}
