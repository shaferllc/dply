<?php

namespace Tests\Unit\Servers\ServerImageCatalogTest;

use App\Models\Server;
use App\Support\Servers\ServerImageCatalog;

test('offers ubuntu and debian options for supported providers', function () {
    $keys = array_column(ServerImageCatalog::optionsForProvider('digitalocean'), 'id');

    expect($keys)->toContain('ubuntu-24-04')
        ->toContain('ubuntu-22-04')
        ->toContain('debian-12')
        ->toContain('debian-11');
});

test('resolves a chosen key to the provider-native slug', function () {
    expect(ServerImageCatalog::resolveSlug('digitalocean', 'debian-12'))->toBe('debian-12-x64');
    expect(ServerImageCatalog::resolveSlug('hetzner', 'debian-12'))->toBe('debian-12');
    expect(ServerImageCatalog::resolveSlug('linode', 'ubuntu-22-04'))->toBe('linode/ubuntu22.04');
});

test('returns null for unknown keys, blank keys, or unmapped providers', function () {
    expect(ServerImageCatalog::resolveSlug('digitalocean', 'windows-2022'))->toBeNull();
    expect(ServerImageCatalog::resolveSlug('digitalocean', ''))->toBeNull();
    expect(ServerImageCatalog::resolveSlug('digitalocean', null))->toBeNull();
    // Provider with no catalog entries falls through so the job uses its config default.
    expect(ServerImageCatalog::resolveSlug('vultr', 'ubuntu-24-04'))->toBeNull();
    expect(ServerImageCatalog::supportsProvider('vultr'))->toBeFalse();
});

test('default key for provider prefers the global default when supported', function () {
    expect(ServerImageCatalog::defaultKeyForProvider('digitalocean'))->toBe('ubuntu-24-04');
});

test('validates whether a key is offered for a provider', function () {
    expect(ServerImageCatalog::isValidForProvider('hetzner', 'debian-11'))->toBeTrue();
    expect(ServerImageCatalog::isValidForProvider('hetzner', 'windows-2022'))->toBeFalse();
    expect(ServerImageCatalog::allowedKeysForProvider('hetzner'))->toContain('debian-11');
});

test('resolves a slug from a server meta os_image', function () {
    $server = new Server;
    $server->meta = ['os_image' => 'debian-12'];

    expect(ServerImageCatalog::resolveForServer($server, 'hetzner'))->toBe('debian-12');

    $without = new Server;
    $without->meta = ['server_role' => 'application'];
    expect(ServerImageCatalog::resolveForServer($without, 'hetzner'))->toBeNull();
});

test('returns a human label for a key', function () {
    expect(ServerImageCatalog::labelFor('ubuntu-24-04'))->toBe('Ubuntu 24.04 LTS');
    expect(ServerImageCatalog::labelFor('debian-12'))->toBe('Debian 12 (Bookworm)');
    expect(ServerImageCatalog::labelFor(''))->toBeNull();
    expect(ServerImageCatalog::labelFor('nope'))->toBeNull();
});
