<?php

declare(strict_types=1);

namespace Tests\Unit\Services\DigitalOceanServiceDatabaseTest;

use App\Modules\Cloud\Services\DigitalOceanService;
use Illuminate\Support\Facades\Http;

test('create database cluster posts and normalizes the response', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/databases' => Http::response([
            'database' => [
                'id' => 'db-abc',
                'status' => 'creating',
                'engine' => 'pg',
                'connection' => [],
            ],
        ], 201),
    ]);

    $cluster = (new DigitalOceanService('tok'))
        ->createDatabaseCluster('pg', 'nyc1', 'db-s-1vcpu-1gb', 'dply-fn-abc');

    expect($cluster['id'])->toBe('db-abc');
    expect($cluster['status'])->toBe('creating');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request['engine'] === 'pg'
        && $request['region'] === 'nyc1'
        && $request['num_nodes'] === 1);
});

test('create remaps portable size and rejected region from the live catalog', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/databases/options*' => Http::response([
            'options' => [
                'valkey' => [
                    'regions' => ['nyc1', 'sfo2'],
                    'versions' => ['8'],
                    'default_version' => '8',
                    'layouts' => [
                        ['num_nodes' => 1, 'sizes' => ['db-s-1vcpu-1gb', 'm-2vcpu-16gb']],
                    ],
                ],
            ],
        ], 200),
        'https://api.digitalocean.com/v2/databases' => Http::response([
            'database' => [
                'id' => 'db-valkey',
                'status' => 'creating',
                'engine' => 'valkey',
                'connection' => [],
            ],
        ], 201),
    ]);

    (new DigitalOceanService('tok'))
        ->createDatabaseCluster('redis', 'sfo3', 'small', 'dply-redis-abc', '7');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_ends_with(parse_url($request->url(), PHP_URL_PATH) ?: '', '/databases')
        && $request['engine'] === 'valkey'
        && $request['version'] === '8'
        && $request['region'] === 'sfo2'
        && $request['size'] === 'db-s-1vcpu-1gb'
        && $request['num_nodes'] === 1);
});

test('create remaps discontinued redis clusters to valkey 8', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/databases' => Http::response([
            'database' => [
                'id' => 'db-valkey',
                'status' => 'creating',
                'engine' => 'valkey',
                'connection' => [],
            ],
        ], 201),
    ]);

    (new DigitalOceanService('tok'))
        ->createDatabaseCluster('redis', 'nyc3', 'db-s-1vcpu-1gb', 'dply-redis-abc', '7');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && $request['engine'] === 'valkey'
        && $request['version'] === '8'
        && $request['region'] === 'nyc3'
        && $request['size'] === 'db-s-1vcpu-1gb'
        && $request['num_nodes'] === 1);
});
test('create connection pool posts transaction mode', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/databases/db-abc/pools' => Http::response([
            'pool' => [
                'name' => 'dply-pool',
                'connection' => [
                    'host' => 'db-abc.db.ondigitalocean.com',
                    'port' => 25061,
                    'user' => 'doadmin',
                    'password' => 'p',
                    'database' => 'dply-pool',
                    'ssl' => true,
                ],
            ],
        ], 201),
    ]);

    $pool = (new DigitalOceanService('tok'))
        ->createDatabaseConnectionPool('db-abc', 'dply-pool', 'defaultdb', 'doadmin');

    expect($pool['name'])->toBe('dply-pool');
    expect($pool['connection']['database'])->toBe('dply-pool');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/databases/db-abc/pools')
        && $request['mode'] === 'transaction');
});
test('get database cluster returns the connection when online', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/databases/db-abc' => Http::response([
            'database' => [
                'id' => 'db-abc',
                'status' => 'online',
                'engine' => 'pg',
                'connection' => [
                    'host' => 'db-abc.db.ondigitalocean.com',
                    'port' => 25060,
                    'user' => 'doadmin',
                    'password' => 'sup3r-s3cret',
                    'database' => 'defaultdb',
                    'ssl' => true,
                ],
            ],
        ], 200),
    ]);

    $cluster = (new DigitalOceanService('tok'))->getDatabaseCluster('db-abc');

    expect($cluster['status'])->toBe('online');
    expect($cluster['connection']['host'])->toBe('db-abc.db.ondigitalocean.com');
    expect($cluster['connection']['port'])->toBe(25060);
    expect($cluster['connection']['password'])->toBe('sup3r-s3cret');
});

test('resize database cluster puts the new size', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/databases/db-abc' => Http::response([
            'database' => [
                'id' => 'db-abc',
                'status' => 'resizing',
                'engine' => 'valkey',
                'connection' => [],
            ],
        ], 200),
    ]);

    $cluster = (new DigitalOceanService('tok'))
        ->resizeDatabaseCluster('db-abc', 'db-s-2vcpu-4gb');

    expect($cluster['id'])->toBe('db-abc')
        ->and($cluster['status'])->toBe('resizing');

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && str_ends_with($request->url(), '/databases/db-abc')
        && $request['size'] === 'db-s-2vcpu-4gb'
        && $request['num_nodes'] === 1);
});
