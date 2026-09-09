<?php

declare(strict_types=1);

use App\Enums\ServerProvider;
use App\Models\Server;
use App\Modules\Database\Backends\DoManagedBackend;
use App\Support\Servers\ProviderManagedDatabaseRegion;

test('digitalocean managed databases remap retired sfo droplet regions', function (): void {
    expect(ProviderManagedDatabaseRegion::normalize('digitalocean', 'sfo2'))->toBe('sfo2')
        ->and(ProviderManagedDatabaseRegion::normalize('digitalocean', 'sfo3'))->toBe('sfo3')
        ->and(ProviderManagedDatabaseRegion::normalize('digitalocean', 'nyc'))->toBe('nyc3')
        ->and(in_array(ProviderManagedDatabaseRegion::normalize('digitalocean', 'sfo'), ['sfo2', 'sfo3'], true))->toBeTrue();
});

test('an explicit region wins over the server fallback', function (): void {
    expect(ProviderManagedDatabaseRegion::resolve('digitalocean', 'nyc3', 'sfo3', ['nyc1', 'nyc3']))->toBe('nyc3');
});

test('filter keeps the live catalog and only drops rejected slugs', function (): void {
    $dump = ['ams3', 'atl1', 'blr1', 'fra1', 'lon1', 'mkc1', 'nyc1', 'nyc2', 'nyc3', 'ric1', 'sfo2', 'sfo3', 'sgp1', 'syd1', 'tor1'];

    expect(ProviderManagedDatabaseRegion::filterForEngine('redis', $dump))->toBe($dump)
        ->and(ProviderManagedDatabaseRegion::filterForEngine('postgres', $dump))->toContain('sfo2', 'nyc2', 'mkc1')
        ->and(ProviderManagedDatabaseRegion::filterForEngine('postgres', $dump, ['sfo3', 'nyc2']))->not->toContain('sfo3', 'nyc2')
        ->and(ProviderManagedDatabaseRegion::rejectedFromError("DigitalOcean API failed to create database cluster: region 'sfo3' is not valid"))
        ->toBe(['sfo3']);
});

test('resolve never returns a region the catalog does not offer', function (): void {
    expect(ProviderManagedDatabaseRegion::resolve('digitalocean', 'sfo3', 'sfo2', ['ams3', 'nyc1', 'nyc3']))
        ->toBe('nyc3')
        ->and(ProviderManagedDatabaseRegion::options('digitalocean', ['nyc1', 'nyc3']))
        ->toEqual([
            ['value' => 'nyc1', 'label' => 'New York · nyc1'],
            ['value' => 'nyc3', 'label' => 'New York · nyc3'],
        ]);
});

test('digitalocean managed backend remaps the app server region before create', function (): void {
    $server = new Server([
        'provider' => ServerProvider::DigitalOcean,
        'region' => 'sfo2',
    ]);

    expect((new DoManagedBackend)->regionForServer($server))->toBe('sfo2');
});
