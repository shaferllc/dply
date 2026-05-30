<?php

declare(strict_types=1);

use App\Models\Server;
use App\Services\Servers\LiveState\OpenRestyLiveStateProbe;

test('openresty live state probe returns standby when another edge proxy is active', function (): void {
    $server = Server::factory()->make([
        'meta' => [
            'webserver' => 'caddy',
            'edge_proxy' => 'envoy',
        ],
    ]);

    $state = app(OpenRestyLiveStateProbe::class)->probe($server, forceFresh: true);

    expect($state->engineSpecific['standby'] ?? false)->toBeTrue()
        ->and($state->units)->toBe([]);
});

test('openresty live state probe parses server and upstream blocks', function (): void {
    $probe = app(OpenRestyLiveStateProbe::class);
    $buildServers = new ReflectionMethod($probe, 'buildServerUnits');
    $buildServers->setAccessible(true);
    $buildUpstreams = new ReflectionMethod($probe, 'buildUpstreamUnits');
    $buildUpstreams->setAccessible(true);

    $config = <<<'CONF'
upstream bk_site {
    server 127.0.0.1:25001;
}
server {
    listen 80;
    server_name app.example.com app.example.com:80;
    location / {
        proxy_pass http://bk_site;
    }
}
server {
    listen 80 default_server;
    server_name _;
    return 503 "dply: no backend matches this host\n";
}
CONF;

    $servers = $buildServers->invoke($probe, $config);
    $upstreams = $buildUpstreams->invoke($probe, $config);

    expect($upstreams)->toHaveCount(1)
        ->and($upstreams[0]['name'])->toBe('bk_site')
        ->and($servers)->not->toBeEmpty();
});
