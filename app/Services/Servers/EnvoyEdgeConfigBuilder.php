<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Models\Site;
use App\Support\Servers\EnvoyConfigParser;
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
     * @param  list<array{name: string, connect_timeout?: string, lb_policy?: string, endpoints?: list<string>}>  $customClusters
     * @param  list<array{name: string, domains?: list<string>, cluster?: string}>  $customVirtualHosts
     * @param  list<array{name: string, address?: string, port?: int, mode?: string, default_cluster?: string}>  $customListeners
     * @param  array{admin_port?: string|int, stat_prefix?: string}  $operatorSettings
     */
    public function build(
        Collection $sites,
        int $listenPort,
        callable $backendPortFor,
        array $customClusters = [],
        array $customVirtualHosts = [],
        array $customListeners = [],
        array $operatorSettings = [],
    ): string {
        $settings = array_merge(
            EnvoyConfigParser::defaultOperatorSettings(),
            $operatorSettings,
        );
        $adminPort = max(1, min(65535, (int) ($settings['admin_port'] ?? 9901)));
        $statPrefix = trim((string) ($settings['stat_prefix'] ?? 'dply_ingress')) ?: 'dply_ingress';

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

        foreach ($customClusters as $custom) {
            if (! is_array($custom)) {
                continue;
            }
            $name = trim((string) ($custom['name'] ?? ''));
            $endpoints = array_values(array_filter(
                array_map('trim', (array) ($custom['endpoints'] ?? [])),
                fn (string $e): bool => $e !== '',
            ));
            if ($name === '' || $endpoints === []) {
                continue;
            }
            $clusters[] = $this->renderCustomCluster(
                $name,
                $endpoints,
                trim((string) ($custom['connect_timeout'] ?? '5s')) ?: '5s',
                trim((string) ($custom['lb_policy'] ?? 'ROUND_ROBIN')) ?: 'ROUND_ROBIN',
            );
        }

        foreach ($customVirtualHosts as $customVhost) {
            if (! is_array($customVhost)) {
                continue;
            }
            $name = trim((string) ($customVhost['name'] ?? ''));
            $cluster = trim((string) ($customVhost['cluster'] ?? ''));
            $domains = array_values(array_filter(
                array_map('trim', (array) ($customVhost['domains'] ?? [])),
                fn (string $d): bool => $d !== '' && $d !== '*',
            ));
            if ($name === '' || $cluster === '' || $domains === []) {
                continue;
            }
            $virtualHosts[] = $this->renderVirtualHost(
                'vhost_custom_'.$this->sanitize($name),
                $this->domainsForPort($domains, $listenPort),
                $cluster,
            );
        }

        $virtualHosts[] = $this->renderUnmatchedVirtualHost();
        $clusterBlock = $clusters !== [] ? implode("\n", $clusters)."\n" : '';

        $listenerBlocks = [
            $this->renderHttpListener(
                'dply_http',
                '0.0.0.0',
                $listenPort,
                $statPrefix,
                $virtualHosts,
            ),
        ];

        foreach ($customListeners as $customListener) {
            if (! is_array($customListener)) {
                continue;
            }
            $name = trim((string) ($customListener['name'] ?? ''));
            $address = trim((string) ($customListener['address'] ?? '0.0.0.0')) ?: '0.0.0.0';
            $port = (int) ($customListener['port'] ?? 0);
            if ($name === '' || $port < 1) {
                continue;
            }
            $mode = strtolower(trim((string) ($customListener['mode'] ?? EnvoyCustomListenersConfig::MODE_SHARED)));
            if ($mode === EnvoyCustomListenersConfig::MODE_CLUSTER) {
                $defaultCluster = trim((string) ($customListener['default_cluster'] ?? ''));
                if ($defaultCluster === '') {
                    continue;
                }
                $altVirtualHosts = [
                    $this->renderVirtualHost(
                        'vhost_'.$this->sanitize($name).'_catchall',
                        ['*'],
                        $defaultCluster,
                    ),
                ];
            } else {
                $altVirtualHosts = $this->buildSiteVirtualHosts($sites, $port, $backendPortFor);
                foreach ($customVirtualHosts as $customVhost) {
                    if (! is_array($customVhost)) {
                        continue;
                    }
                    $vhostName = trim((string) ($customVhost['name'] ?? ''));
                    $cluster = trim((string) ($customVhost['cluster'] ?? ''));
                    $domains = array_values(array_filter(
                        array_map('trim', (array) ($customVhost['domains'] ?? [])),
                        fn (string $d): bool => $d !== '' && $d !== '*',
                    ));
                    if ($vhostName === '' || $cluster === '' || $domains === []) {
                        continue;
                    }
                    $altVirtualHosts[] = $this->renderVirtualHost(
                        'vhost_custom_'.$this->sanitize($vhostName),
                        $this->domainsForPort($domains, $port),
                        $cluster,
                    );
                }
                $altVirtualHosts[] = $this->renderUnmatchedVirtualHost();
            }

            $listenerBlocks[] = $this->renderHttpListener(
                $this->sanitize($name),
                $address,
                $port,
                $statPrefix.'_'.$this->sanitize($name),
                $altVirtualHosts,
            );
        }

        $config = <<<YAML
# Managed by Dply — do NOT hand-edit. Regenerated on every webserver switch.
admin:
  address:
    socket_address:
      address: 127.0.0.1
      port_value: {$adminPort}

static_resources:
  listeners:
{$this->indentListenerBlocks($listenerBlocks)}
  clusters:
{$clusterBlock}
YAML;

        return rtrim($config)."\n";
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @return list<string>
     */
    private function buildSiteVirtualHosts(Collection $sites, int $listenPort, callable $backendPortFor): array
    {
        $virtualHosts = [];

        foreach ($sites as $site) {
            $basename = $this->basenameFor($site);
            $clusterName = 'cluster_'.$this->sanitize($basename);
            $vhostName = 'vhost_'.$this->sanitize($basename);
            $hostnames = $this->hostnamesFor($site);
            if ($hostnames === []) {
                continue;
            }

            $virtualHosts[] = $this->renderVirtualHost(
                $vhostName,
                $this->domainsForPort($hostnames, $listenPort),
                $clusterName,
            );
        }

        return $virtualHosts;
    }

    /**
     * @param  list<string>  $domains
     * @return list<string>
     */
    private function domainsForPort(array $domains, int $listenPort): array
    {
        $out = [];
        foreach ($domains as $domain) {
            $domain = trim($domain);
            if ($domain === '') {
                continue;
            }
            $out[] = $domain;
            $out[] = $domain.':'.$listenPort;
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  list<string>  $virtualHosts
     */
    private function renderHttpListener(
        string $name,
        string $address,
        int $port,
        string $statPrefix,
        array $virtualHosts,
    ): string {
        $virtualHostBlock = implode("\n", $virtualHosts);

        return <<<YAML
  - name: {$name}
    address:
      socket_address:
        address: {$address}
        port_value: {$port}
    filter_chains:
    - filters:
      - name: envoy.filters.network.http_connection_manager
        typed_config:
          "@type": type.googleapis.com/envoy.extensions.filters.network.http_connection_manager.v3.HttpConnectionManager
          stat_prefix: {$statPrefix}
          route_config:
            name: dply_routes_{$name}
            virtual_hosts:
{$virtualHostBlock}
          http_filters:
          - name: envoy.filters.http.router
            typed_config:
              "@type": type.googleapis.com/envoy.extensions.filters.http.router.v3.Router
YAML;
    }

    /**
     * @param  list<string>  $blocks
     */
    private function indentListenerBlocks(array $blocks): string
    {
        return implode("\n", $blocks);
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
        return $this->renderCustomCluster($clusterName, ['127.0.0.1:'.$port], '5s', 'ROUND_ROBIN');
    }

    /**
     * @param  list<string>  $endpoints
     */
    private function renderCustomCluster(string $clusterName, array $endpoints, string $connectTimeout, string $lbPolicy): string
    {
        $endpointLines = [];
        foreach ($endpoints as $endpoint) {
            [$host, $port] = $this->splitHostPort($endpoint);
            $endpointLines[] = <<<YAML
        - endpoint:
            address:
              socket_address:
                address: {$host}
                port_value: {$port}
YAML;
        }
        $lbBlock = implode("\n", $endpointLines);

        return <<<YAML
  - name: {$clusterName}
    connect_timeout: {$connectTimeout}
    type: STATIC
    lb_policy: {$lbPolicy}
    load_assignment:
      cluster_name: {$clusterName}
      endpoints:
      - lb_endpoints:
{$lbBlock}
YAML;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function splitHostPort(string $endpoint): array
    {
        if (preg_match('/^(.+):(\d+)$/', trim($endpoint), $m) === 1) {
            return [$m[1], (int) $m[2]];
        }

        return ['127.0.0.1', 8080];
    }

    /**
     * @param  Collection<int, Site>  $sites
     */
    public function buildForServer(Server $server, Collection $sites, int $listenPort, callable $backendPortFor): string
    {
        return $this->build(
            $sites,
            $listenPort,
            $backendPortFor,
            EnvoyCustomClustersConfig::clustersFromServer($server),
            EnvoyCustomVirtualHostsConfig::virtualHostsFromServer($server),
            EnvoyCustomListenersConfig::listenersFromServer($server),
            EnvoyStaticConfigOptions::operatorSettingsFromServer($server),
        );
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
