<?php

declare(strict_types=1);

use App\Models\Server;
use App\Models\Site;
use App\Support\Sites\LetsEncryptCertbotCommandBuilder;

test('edge proxy servers use certbot webroot challenge', function (): void {
    $server = Server::factory()->make([
        'meta' => ['edge_proxy' => 'envoy', 'webserver' => 'nginx'],
    ]);

    $site = Site::factory()->make([
        'document_root' => '/var/www/demo/public',
    ]);
    $site->setRelation('server', $server);

    $cmd = LetsEncryptCertbotCommandBuilder::build($site, ['testing-a6a4129f.ondply.io'], 'ops@example.com');

    expect($cmd)
        ->toContain('set -e')
        ->toContain('mkdir -p')
        ->toContain('.well-known/acme-challenge')
        ->toContain('[dply] ACME preflight failed')
        ->toContain('certbot certonly --webroot')
        ->toContain('--preferred-challenges http')
        ->toContain('/var/www/demo/public')
        ->toContain('testing-a6a4129f.ondply.io')
        ->not->toContain('certbot --nginx');
});

test('plain nginx servers use certbot webroot to avoid nginx restarts', function (): void {
    $server = Server::factory()->make([
        'meta' => ['webserver' => 'nginx'],
    ]);

    $site = Site::factory()->make([
        'document_root' => '/var/www/demo/public',
    ]);
    $site->setRelation('server', $server);

    $cmd = LetsEncryptCertbotCommandBuilder::build($site, ['app.example.com'], 'ops@example.com');

    expect($cmd)
        ->toContain('certbot certonly --webroot')
        ->toContain('/var/www/demo/public')
        ->not->toContain('certbot --nginx');
});
