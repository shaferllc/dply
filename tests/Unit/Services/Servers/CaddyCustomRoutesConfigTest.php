<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\Server;
use App\Services\Servers\CaddyCustomRoutesConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function caddyCustomRoutesServer(array $meta = []): Server
{
    $org = Organization::factory()->create();

    return Server::factory()->for($org)->create([
        'meta' => $meta,
    ]);
}

test('caddy custom route render builds static site block', function () {
    $config = app(CaddyCustomRoutesConfig::class);
    $server = caddyCustomRoutesServer();

    $rendered = $config->render($server, 'legacy-api', [
        'hosts' => ['api.example.com', 'www.example.com'],
        'root' => '/var/www/legacy/public',
        'upstream' => '',
    ]);

    expect($rendered)->toContain('api.example.com, www.example.com {')
        ->and($rendered)->toContain('root * /var/www/legacy/public')
        ->and($rendered)->toContain('file_server');
});

test('caddy custom route render builds php route', function () {
    $config = app(CaddyCustomRoutesConfig::class);
    $server = caddyCustomRoutesServer([
        'php_inventory' => [
            'installed_versions' => ['8.3'],
            'detected_default_version' => '8.3',
        ],
    ]);

    $rendered = $config->render($server, 'legacy-php', [
        'hosts' => ['app.example.com'],
        'root' => '/var/www/app/public',
        'upstream' => 'unix:/run/php/php8.3-fpm.sock',
    ]);

    expect($rendered)->toContain('php_fastcgi unix//run/php/php8.3-fpm.sock')
        ->and($rendered)->toContain('file_server');
});

test('caddy custom route render rewrites stale php upstream to latest installed', function () {
    $config = app(CaddyCustomRoutesConfig::class);
    $server = caddyCustomRoutesServer([
        'php_inventory' => [
            'installed_versions' => ['8.4'],
            'detected_default_version' => '8.4',
        ],
    ]);

    $rendered = $config->render($server, 'legacy-php', [
        'hosts' => ['app.example.com'],
        'root' => '/var/www/app/public',
        'upstream' => 'unix:/run/php/php8.3-fpm.sock',
    ]);

    expect($rendered)->toContain('php_fastcgi unix//run/php/php8.4-fpm.sock');
});

test('caddy custom route render builds reverse proxy route', function () {
    $config = app(CaddyCustomRoutesConfig::class);
    $server = caddyCustomRoutesServer();

    $rendered = $config->render($server, 'api-proxy', [
        'hosts' => ['api.example.com'],
        'root' => '',
        'upstream' => '127.0.0.1:3000',
    ]);

    expect($rendered)->toContain('reverse_proxy http://127.0.0.1:3000')
        ->and($rendered)->not->toContain('file_server');
});

test('caddy custom route requires hostnames', function () {
    $config = app(CaddyCustomRoutesConfig::class);
    $server = caddyCustomRoutesServer();

    expect(fn () => $config->render($server, 'legacy', [
        'hosts' => [],
        'root' => '/var/www/a',
        'upstream' => '',
    ]))->toThrow(InvalidArgumentException::class);
});
