<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\DigitalOceanService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DigitalOceanServiceDatabaseTest extends TestCase
{
    public function test_create_database_cluster_posts_and_normalizes_the_response(): void
    {
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

        $this->assertSame('db-abc', $cluster['id']);
        $this->assertSame('creating', $cluster['status']);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request['engine'] === 'pg'
            && $request['region'] === 'nyc1'
            && $request['num_nodes'] === 1);
    }

    public function test_create_connection_pool_posts_transaction_mode(): void
    {
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

        $this->assertSame('dply-pool', $pool['name']);
        $this->assertSame('dply-pool', $pool['connection']['database']);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/databases/db-abc/pools')
            && $request['mode'] === 'transaction');
    }

    public function test_get_database_cluster_returns_the_connection_when_online(): void
    {
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

        $this->assertSame('online', $cluster['status']);
        $this->assertSame('db-abc.db.ondigitalocean.com', $cluster['connection']['host']);
        $this->assertSame(25060, $cluster['connection']['port']);
        $this->assertSame('sup3r-s3cret', $cluster['connection']['password']);
    }
}
