<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use App\Services\Servers\TraefikStaticConfigOptions;
use App\Support\Servers\TraefikAdminUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders canonical traefik static yaml with web port and api entry point', function (): void {
    $yaml = app(TraefikStaticConfigOptions::class)->renderCanonicalStaticYaml(8080);

    expect($yaml)
        ->toContain('address: ":8080"')
        ->toContain('address: "127.0.0.1:9094"')
        ->toContain('insecure: true')
        ->toContain('directory: /etc/traefik/dynamic');
});

it('resolves web listen port from parsed static config', function (array $parsed, int $expected): void {
    expect(app(TraefikStaticConfigOptions::class)->resolveWebListenPortFromParsed($parsed))->toBe($expected);
})->with([
    [['entryPoints' => ['web' => ['address' => ':80']]], 80],
    [['entryPoints' => ['web' => ['address' => ':8080']]], 8080],
    [['entryPoints' => []], 80],
]);

it('forces dply api defaults on edge traefik servers when ensuring static config', function (): void {
    $org = Organization::factory()->create();
    $user = User::factory()->create();
    $user->organizations()->attach($org->id, ['role' => 'owner']);
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'meta' => ['edge_proxy' => 'traefik'],
    ]);
    $parsed = [
        'entryPoints' => [
            'traefik' => ['address' => ':8080'],
        ],
        'api' => [
            'dashboard' => false,
            'insecure' => false,
        ],
    ];

    $merged = app(TraefikStaticConfigOptions::class)->ensureDplyTraefikStaticDefaults($server, $parsed);

    expect($merged['entryPoints']['traefik']['address'])->toBe(TraefikAdminUrl::DEFAULT_ADDRESS)
        ->and($merged['api']['dashboard'])->toBeTrue()
        ->and($merged['api']['insecure'])->toBeTrue()
        ->and($merged['entryPoints']['web']['address'])->toBe(':80');
});
