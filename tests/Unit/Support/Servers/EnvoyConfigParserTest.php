<?php

declare(strict_types=1);

use App\Support\Servers\EnvoyConfigParser;

test('envoy config parser extracts virtual hosts and custom clusters', function (): void {
    $yaml = <<<'YAML'
admin:
  address:
    socket_address:
      address: 127.0.0.1
      port_value: 9901
static_resources:
  listeners:
  - name: dply_http
    filter_chains:
    - filters:
      - name: envoy.filters.network.http_connection_manager
        typed_config:
          route_config:
            virtual_hosts:
            - name: vhost_dply-abc123-alpha
              domains: ["alpha.example.com"]
              routes:
              - match: { prefix: "/" }
                route: { cluster: cluster_site_alpha }
            - name: dply_unmatched
              domains: ["*"]
              routes:
              - match: { prefix: "/" }
                direct_response: { status: 503 }
  clusters:
  - name: cluster_site_alpha
    load_assignment:
      endpoints:
      - lb_endpoints:
        - endpoint:
            address:
              socket_address:
                address: 127.0.0.1
                port_value: 25001
  - name: api_pool
    load_assignment:
      endpoints:
      - lb_endpoints:
        - endpoint:
            address:
              socket_address:
                address: 127.0.0.1
                port_value: 9090
YAML;

    $hosts = EnvoyConfigParser::virtualHosts($yaml);
    expect($hosts)->toHaveCount(2)
        ->and($hosts[0]['cluster'])->toBe('cluster_site_alpha')
        ->and($hosts[0]['site_id'])->toBe('abc123');

    $custom = EnvoyConfigParser::customClusters($yaml);
    expect($custom)->toHaveCount(1)
        ->and($custom[0]['name'])->toBe('api_pool')
        ->and($custom[0]['endpoints'])->toBe(['127.0.0.1:9090']);
});

test('envoy config parser merges operator settings', function (): void {
    $yaml = <<<'YAML'
admin:
  address:
    socket_address:
      address: 127.0.0.1
      port_value: 9901
static_resources:
  listeners:
  - filter_chains:
    - filters:
      - name: envoy.filters.network.http_connection_manager
        typed_config:
          stat_prefix: dply_ingress
YAML;

    $merged = EnvoyConfigParser::mergeOperatorSettings($yaml, [
        'admin_port' => '9903',
        'stat_prefix' => 'custom_prefix',
    ]);

    expect($merged)->toContain('port_value: 9903')
        ->and($merged)->toContain('stat_prefix: custom_prefix');
});
