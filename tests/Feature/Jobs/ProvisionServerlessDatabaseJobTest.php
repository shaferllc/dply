<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs\ProvisionServerlessDatabaseJobTest;
use App\Jobs\ProvisionServerlessDatabaseJob;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Services\Deploy\ServerlessEnvironmentPreparer;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $database
 */
function functionSite(array $database): Site
{
    $credential = ProviderCredential::query()->create([
        'organization_id' => ($org = \App\Models\Organization::factory()->create())->id,
        'user_id' => ($user = \App\Models\User::factory()->create())->id,
        'provider' => 'digitalocean',
        'name' => 'DO',
        'credentials' => ['token' => 'tok'],
    ]);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider_credential_id' => $credential->id,
        'region' => 'nyc1',
        'status' => Server::STATUS_READY,
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
    ]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'meta' => ['serverless' => ['database' => $database]],
    ]);
}
function runJob(Site $site): void
{
    (new ProvisionServerlessDatabaseJob($site->id))->handle(app(ServerlessEnvironmentPreparer::class));
}
test('an online cluster injects the connection into the environment', function () {
    Http::fake([
        'https://api.digitalocean.com/v2/databases/*/pools' => Http::response([
            'pool' => [
                'name' => 'dply-pool',
                'connection' => [
                    'host' => 'db-1.ondigitalocean.com',
                    'port' => 25061,
                    'user' => 'doadmin',
                    'password' => 'pa55 word!',
                    'database' => 'dply-pool',
                    'ssl' => true,
                ],
            ],
        ], 201),
        'https://api.digitalocean.com/v2/databases' => Http::response([
            'database' => [
                'id' => 'db-1',
                'status' => 'online',
                'engine' => 'pg',
                'connection' => [
                    'host' => 'db-1.ondigitalocean.com',
                    'port' => 25060,
                    'user' => 'doadmin',
                    'password' => 'pa55 word!',
                    'database' => 'defaultdb',
                    'ssl' => true,
                ],
            ],
        ], 201),
    ]);

    $site = functionSite(['engine' => 'pg', 'size' => 'db-s-1vcpu-1gb', 'status' => 'provisioning']);

    runJob($site);

    $site->refresh();
    expect(data_get($site->meta, 'serverless.database.status'))->toBe('online');
    expect(data_get($site->meta, 'serverless.database.pooled'))->toBeTrue();

    $env = (string) $site->env_file_content;
    $this->assertStringContainsString('DB_CONNECTION=pgsql', $env);

    // A Postgres connection is routed through the pool.
    $this->assertStringContainsString('DB_DATABASE=dply-pool', $env);
    $this->assertStringContainsString('DB_PORT=25061', $env);

    // A password with spaces is quoted so Dotenv parses it intact.
    $this->assertStringContainsString('DB_PASSWORD="pa55 word!"', $env);
});
test('a still creating cluster re polls', function () {
    Bus::fake();
    Http::fake([
        'https://api.digitalocean.com/v2/databases' => Http::response([
            'database' => ['id' => 'db-2', 'status' => 'creating', 'engine' => 'pg', 'connection' => []],
        ], 201),
    ]);

    $site = functionSite(['engine' => 'pg', 'size' => 'db-s-1vcpu-1gb', 'status' => 'provisioning']);

    runJob($site);

    expect(data_get($site->fresh()->meta, 'serverless.database.status'))->toBe('provisioning');
    expect(data_get($site->fresh()->meta, 'serverless.database.cluster_id'))->toBe('db-2');
    Bus::assertDispatched(ProvisionServerlessDatabaseJob::class);
});
test('it records an error when the host has no credential', function () {
    $site = functionSite(['engine' => 'pg', 'size' => 'db-s-1vcpu-1gb', 'status' => 'provisioning']);
    $site->server->forceFill(['provider_credential_id' => null])->save();

    runJob($site);

    expect(data_get($site->fresh()->meta, 'serverless.database.status'))->toBe('error');
});
