<?php


namespace Tests\Feature\Actions\Serverless\CreateServerlessFunctionTest;
use InvalidArgumentException;

use App\Actions\Serverless\CreateServerlessFunction;
use App\Jobs\ProvisionServerlessHostJob;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function handle(array $overrides = []): Site
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $credential = ProviderCredential::query()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'provider' => 'digitalocean',
        'name' => 'DO main',
        'credentials' => ['token' => 'dop_v1_test'],
    ]);

    return handle($user, $org, array_merge([
        'name' => 'My API',
        'repo' => 'acme/api',
        'branch' => 'main',
        'runtime' => 'nodejs:20',
        'region' => 'nyc1',
        'provider_credential_id' => $credential->id,
    ], $overrides));
}

test('creates a serverless host and function site', function () {
    Bus::fake();

    $site = handle();

    $server = Server::find($site->server_id);
    expect($server->isServerlessHost())->toBeTrue();
    expect($server->hostKind())->toBe(Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS);

    // Host starts PENDING — the provision job creates the namespace then
    // marks it READY.
    expect($server->status)->toBe(Server::STATUS_PENDING);

    expect($site->status)->toBe(Site::STATUS_FUNCTIONS_CONFIGURED);
    expect($site->git_repository_url)->toBe('acme/api');
    expect($site->git_branch)->toBe('main');
    expect($site->meta['serverless']['runtime'])->toBe('nodejs:20');
    expect($site->meta['runtime_profile'])->toBe('digitalocean_functions_web');
});

test('dispatches the namespace provision job', function () {
    Bus::fake();

    $site = handle();

    Bus::assertDispatched(
        ProvisionServerlessHostJob::class,
        fn (ProvisionServerlessHostJob $job) => $job->serverId === $site->server_id,
    );
});

test('normalizes a full github url to owner repo', function () {
    Bus::fake();

    $site = handle(['repo' => 'https://github.com/acme/widgets.git']);

    expect($site->git_repository_url)->toBe('acme/widgets');
});

test('rejects an empty repository', function () {
    Bus::fake();

    $this->expectException(InvalidArgumentException::class);
    handle(['repo' => '']);
});

test('function site is not billed until active', function () {
    Bus::fake();

    // Fresh function is `functions_configured`, not `functions_active` —
    // the billing computer only counts active functions.
    $site = handle();

    $this->assertNotSame(Site::STATUS_FUNCTIONS_ACTIVE, $site->status);
});

test('auto runtime is stored unset for deploy time detection', function () {
    Bus::fake();

    // `auto` leaves the runtime empty so ServerlessRuntimeDetector picks
    // it from the repo at deploy time; an explicit value is kept verbatim.
    $site = handle(['runtime' => 'auto']);

    expect($site->meta['serverless']['runtime'])->toBe('');
});