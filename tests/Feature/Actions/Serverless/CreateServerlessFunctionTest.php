<?php

namespace Tests\Feature\Actions\Serverless\CreateServerlessFunctionTest;

use App\Modules\Serverless\Actions\CreateServerlessFunction;
use App\Modules\Serverless\Jobs\ProvisionServerlessHostJob;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

uses(RefreshDatabase::class);

function configureManagedServerless(): void
{
    Config::set('serverless.managed.api_host', 'https://faas-nyc1.doserverless.co');
    Config::set('serverless.managed.namespace', 'fn-dply-shared');
    Config::set('serverless.managed.access_key', 'uuid:secretkey');
    Config::set('serverless.managed.region', 'nyc1');
}

function createFunction(array $overrides = []): Site
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

    return app(CreateServerlessFunction::class)->handle($user, $org, array_merge([
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

    $site = createFunction();

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

    $site = createFunction();

    Bus::assertDispatched(
        ProvisionServerlessHostJob::class,
        fn (ProvisionServerlessHostJob $job) => $job->serverId === $site->server_id,
    );
});

test('normalizes a full github url to owner repo', function () {
    Bus::fake();

    $site = createFunction(['repo' => 'https://github.com/acme/widgets.git']);

    expect($site->git_repository_url)->toBe('acme/widgets');
});

test('rejects an empty repository', function () {
    Bus::fake();

    $this->expectException(InvalidArgumentException::class);
    createFunction(['repo' => '']);
});

test('function site is not billed until active', function () {
    Bus::fake();

    // Fresh function is `functions_configured`, not `functions_active` —
    // the billing computer only counts active functions.
    $site = createFunction();

    $this->assertNotSame(Site::STATUS_FUNCTIONS_ACTIVE, $site->status);
});

test('auto runtime is stored unset for deploy time detection', function () {
    Bus::fake();

    // `auto` leaves the runtime empty so ServerlessRuntimeDetector picks
    // it from the repo at deploy time; an explicit value is kept verbatim.
    $site = createFunction(['runtime' => 'auto']);

    expect($site->meta['serverless']['runtime'])->toBe('');
});

test('byo functions record the customer backend and credential', function () {
    Bus::fake();

    $site = createFunction();

    expect($site->serverless_backend)->toBe(Site::SERVERLESS_BACKEND_BYO);
    expect($site->serverless_provider_credential_id)->not->toBeNull();
    expect($site->usesManagedServerless())->toBeFalse();

    $server = Server::find($site->server_id);
    expect($server->provider_credential_id)->not->toBeNull();
    expect($server->meta['serverless_managed'] ?? false)->toBeFalse();
});

test('managed mode runs on dplys account without a customer credential', function () {
    Bus::fake();
    configureManagedServerless();

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    // No provider_credential_id supplied — managed functions don't need one.
    $site = app(CreateServerlessFunction::class)->handle($user, $org, [
        'name' => 'Managed API',
        'repo' => 'acme/api',
        'branch' => 'main',
        'runtime' => 'nodejs:20',
        'region' => 'nyc1',
        'delivery_mode' => 'managed',
    ]);

    expect($site->serverless_backend)->toBe(Site::SERVERLESS_BACKEND_DPLY);
    expect($site->serverless_provider_credential_id)->toBeNull();
    expect($site->usesManagedServerless())->toBeTrue();

    $server = Server::find($site->server_id);
    expect($server->provider_credential_id)->toBeNull();
    expect($server->meta['serverless_managed'])->toBeTrue();
});

test('managed mode is rejected when the platform namespace is not configured', function () {
    Bus::fake();
    Config::set('serverless.managed.api_host', '');
    Config::set('serverless.managed.namespace', '');
    Config::set('serverless.managed.access_key', '');

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $this->expectException(InvalidArgumentException::class);

    app(CreateServerlessFunction::class)->handle($user, $org, [
        'name' => 'Managed API',
        'repo' => 'acme/api',
        'branch' => 'main',
        'runtime' => 'nodejs:20',
        'region' => 'nyc1',
        'delivery_mode' => 'managed',
    ]);
});
