<?php

declare(strict_types=1);

use App\Models\Server;
use App\Support\Servers\EdgeProxyWorkspaceViewData;

test('edge proxy previous webserver reads stored meta', function (): void {
    $server = Server::factory()->make([
        'meta' => [
            'edge_proxy' => 'traefik',
            'edge_proxy_previous_webserver' => 'nginx',
            'webserver' => 'caddy',
        ],
    ]);

    expect(EdgeProxyWorkspaceViewData::previousWebserverKey($server))->toBe('nginx');
    expect(EdgeProxyWorkspaceViewData::previousWebserverLabel($server))->toBe('nginx');
});

test('edge proxy previous webserver falls back to meta webserver', function (): void {
    $server = Server::factory()->make([
        'meta' => [
            'edge_proxy' => 'traefik',
            'webserver' => 'apache',
        ],
    ]);

    expect(EdgeProxyWorkspaceViewData::previousWebserverKey($server))->toBe('apache');
});
