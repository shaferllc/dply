<?php

namespace Tests\Unit\Servers\ServerImageCatalogTest;

use App\Models\Server;
use App\Support\Servers\ServerImageCatalog;

test('offers only the enabled images for supported providers', function () {
    $keys = array_column(ServerImageCatalog::optionsForProvider('digitalocean'), 'id');

    expect($keys)->toContain('ubuntu-24-04');
});

test('images marked enabled=false are withdrawn from the picker and validation', function () {
    $keys = array_column(ServerImageCatalog::optionsForProvider('digitalocean'), 'id');

    // Still mapped in config (so provisioned servers resolve), just not offered.
    foreach (['ubuntu-22-04', 'ubuntu-20-04', 'debian-12', 'debian-11'] as $retired) {
        expect($keys)->not->toContain($retired);
        expect(ServerImageCatalog::isOffered($retired))->toBeFalse();
        expect(ServerImageCatalog::isValidForProvider('digitalocean', $retired))->toBeFalse();
        expect(ServerImageCatalog::allowedKeysForProvider('digitalocean'))->not->toContain($retired);
    }
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
    expect(ServerImageCatalog::resolveSlug('custom', 'ubuntu-24-04'))->toBeNull();
    expect(ServerImageCatalog::supportsProvider('custom'))->toBeFalse();
});

test('offers vultr os ids as stringified catalog slugs', function () {
    expect(ServerImageCatalog::supportsProvider('vultr'))->toBeTrue();
    expect(ServerImageCatalog::resolveSlug('vultr', 'ubuntu-24-04'))->toBe('2284');
    expect(ServerImageCatalog::resolveSlug('vultr', 'debian-12'))->toBe('2136');
    expect(ServerImageCatalog::defaultKeyForProvider('vultr'))->toBe('ubuntu-24-04');
});

test('default key for provider prefers the global default when supported', function () {
    expect(ServerImageCatalog::defaultKeyForProvider('digitalocean'))->toBe('ubuntu-24-04');
});

test('validates whether a key is offered for a provider', function () {
    expect(ServerImageCatalog::isValidForProvider('hetzner', 'ubuntu-24-04'))->toBeTrue();
    expect(ServerImageCatalog::isValidForProvider('hetzner', 'windows-2022'))->toBeFalse();
    expect(ServerImageCatalog::allowedKeysForProvider('hetzner'))->toContain('ubuntu-24-04');
});

test('resolves a slug from a server meta os_image', function () {
    $server = new Server;
    $server->meta = ['os_image' => 'debian-12'];

    expect(ServerImageCatalog::resolveForServer($server, 'hetzner'))->toBe('debian-12');

    $without = new Server;
    $without->meta = ['server_role' => 'application'];
    expect(ServerImageCatalog::resolveForServer($without, 'hetzner'))->toBeNull();
});

test('reads a stamped worker boot image id', function () {
    $server = new Server;
    $server->meta = ['boot_image_id' => '170123456'];

    expect(ServerImageCatalog::bootImageForServer($server))->toBe('170123456');

    $without = new Server;
    $without->meta = ['os_image' => 'ubuntu-24-04'];
    expect(ServerImageCatalog::bootImageForServer($without))->toBeNull();
});

test('returns a human label for a key', function () {
    expect(ServerImageCatalog::labelFor('ubuntu-24-04'))->toBe('Ubuntu 24.04 LTS');
    expect(ServerImageCatalog::labelFor('debian-12'))->toBe('Debian 12 (Bookworm)');
    expect(ServerImageCatalog::labelFor(''))->toBeNull();
    expect(ServerImageCatalog::labelFor('nope'))->toBeNull();
});
