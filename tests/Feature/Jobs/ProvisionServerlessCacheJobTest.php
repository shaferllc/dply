<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs\ProvisionServerlessCacheJobTest;

use App\Jobs\ProvisionServerlessCacheJob;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Deploy\ServerlessEnvironmentPreparer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('an online redis cluster wires in the cache env', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/databases' => Http::response([
            'database' => [
                'id' => 'redis-1',
                'status' => 'online',
                'engine' => 'redis',
                'connection' => [
                    'host' => 'redis-1.ondigitalocean.com',
                    'port' => 25061,
                    'user' => 'default',
                    'password' => 'r3dis-pw',
                    'uri' => 'rediss://default:r3dis-pw@redis-1.ondigitalocean.com:25061',
                    'ssl' => true,
                ],
            ],
        ], 201),
    ]);

    $org = Organization::factory()->create();
    $user = User::factory()->create();
    $credential = ProviderCredential::query()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'provider' => 'digitalocean',
        'name' => 'DO',
        'credentials' => ['token' => 'tok'],
    ]);
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'region' => 'nyc1',
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => ['serverless' => ['cache' => ['size' => 'db-s-1vcpu-1gb', 'status' => 'provisioning']]],
    ]);

    (new ProvisionServerlessCacheJob($site->id))->handle(app(ServerlessEnvironmentPreparer::class));

    $site->refresh();
    expect(data_get($site->meta, 'serverless.cache.status'))->toBe('online');

    $env = (string) $site->env_file_content;

    // The URL contains @ so it is quoted for Dotenv.
    $this->assertStringContainsString('REDIS_URL="rediss://default:r3dis-pw@redis-1.ondigitalocean.com:25061"', $env);
    $this->assertStringContainsString('CACHE_STORE=redis', $env);
    $this->assertStringContainsString('REDIS_HOST=redis-1.ondigitalocean.com', $env);
});
