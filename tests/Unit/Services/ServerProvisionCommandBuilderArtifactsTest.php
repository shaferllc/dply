<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ServerProvisionCommandBuilderArtifactsTest;

use App\Models\Server;
use App\Services\Servers\ServerProvisionCommandBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it builds rendered config and verification artifacts', function () {
    $server = new Server([
        'name' => 'App Server',
        'meta' => [
            'server_role' => 'application',
            'webserver' => 'nginx',
            'php_version' => '8.3',
            'database' => 'mysql84',
            'cache_service' => 'redis',
        ],
    ]);

    $artifacts = app(ServerProvisionCommandBuilder::class)->buildArtifacts($server);

    expect($artifacts)->not->toBeEmpty();
    expect(collect($artifacts)->contains(fn (array $artifact): bool => $artifact['type'] === 'rendered_config' && $artifact['key'] === 'nginx-starter'))->toBeTrue();
    expect(collect($artifacts)->contains(fn (array $artifact): bool => $artifact['type'] === 'verification_plan'))->toBeTrue();
    expect(collect($artifacts)->contains(fn (array $artifact): bool => $artifact['type'] === 'rollback_plan'))->toBeTrue();
});
test('it builds apache openlitespeed and traefik artifacts', function () {
    $builder = app(ServerProvisionCommandBuilder::class);

    $apacheServer = new Server([
        'meta' => [
            'server_role' => 'application',
            'webserver' => 'apache',
            'php_version' => '8.3',
            'database' => 'mysql84',
            'cache_service' => 'redis',
        ],
    ]);
    $olsServer = new Server([
        'meta' => [
            'server_role' => 'application',
            'webserver' => 'openlitespeed',
            'php_version' => '8.3',
            'database' => 'mysql84',
            'cache_service' => 'redis',
        ],
    ]);
    $traefikServer = new Server([
        'meta' => [
            'server_role' => 'application',
            'webserver' => 'traefik',
            'php_version' => '8.3',
            'database' => 'mysql84',
            'cache_service' => 'redis',
        ],
    ]);

    expect(collect($builder->buildArtifacts($apacheServer))->contains(fn (array $artifact): bool => $artifact['key'] === 'apache-starter'))->toBeTrue();
    expect(collect($builder->buildArtifacts($olsServer))->contains(fn (array $artifact): bool => $artifact['key'] === 'openlitespeed-starter'))->toBeTrue();
    expect(collect($builder->buildArtifacts($traefikServer))->contains(fn (array $artifact): bool => $artifact['key'] === 'traefik-starter'))->toBeTrue();
});
test('docker role stays container focused', function () {
    $server = new Server([
        'meta' => [
            'server_role' => 'docker',
            'webserver' => 'nginx',
            'php_version' => '8.3',
            'database' => 'mysql84',
            'cache_service' => 'redis',
        ],
    ]);

    $script = implode("\n", app(ServerProvisionCommandBuilder::class)->build($server));

    $this->assertStringContainsString('Installing Docker', $script);
    $this->assertStringNotContainsString('Installing Composer', $script);
    $this->assertStringNotContainsString('Installing PHP 8.3', $script);
});

test('it omits the php handler from starter vhosts when the server has no php', function () {
    $builder = app(ServerProvisionCommandBuilder::class);

    $content = function (string $webserver, ?string $phpVersion) use ($builder): string {
        $artifacts = $builder->buildArtifacts(new Server([
            'meta' => [
                'server_role' => 'application',
                'webserver' => $webserver,
                'php_version' => $phpVersion,
                'database' => 'mysql84',
                'cache_service' => 'redis',
            ],
        ]));

        return (string) collect($artifacts)
            ->firstWhere('key', $webserver.'-starter')['content'];
    };

    // A server provisioned without PHP has no fpm socket to point at. Rendering
    // the handler anyway produced `fastcgi_pass unix:;`, which fails `nginx -t`
    // and blocked every later reload on the box — including the site's own vhost.
    expect($content('nginx', 'none'))
        ->not->toContain('unix:')
        ->and($content('nginx', 'none'))->not->toContain('fastcgi_pass')
        ->and($content('apache', 'none'))->not->toContain('proxy:unix:');

    expect($content('nginx', '8.3'))->toContain('fastcgi_pass unix:/run/php/php8.3-fpm.sock;')
        ->and($content('apache', '8.3'))->toContain('proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost/');
});
