<?php

namespace Tests\Feature\Jobs\ProvisionServerlessHostJobTest;

use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Deploy\Jobs\RunSiteDeploymentJob;
use App\Modules\Serverless\Jobs\ProvisionServerlessHostJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function makeHost(array $serverMeta = []): Server
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

    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'provider_credential_id' => $credential->id,
        'region' => 'nyc1',
        'status' => Server::STATUS_PENDING,
        'meta' => array_merge(['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS], $serverMeta),
    ]);

    Site::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'server_id' => $server->id,
        'status' => Site::STATUS_FUNCTIONS_CONFIGURED,
    ]);

    return $server;
}

function fakeNamespaceApi(): void
{
    Http::fake([
        'api.digitalocean.com/v2/functions/namespaces' => Http::response([
            'namespace' => [
                'api_host' => 'https://faas-nyc1.doserverless.co',
                'namespace' => 'fn-abc123',
                'key' => 'abc:secret',
                'region' => 'nyc1',
            ],
        ], 200),
    ]);
}

test('provisions namespace metadata and marks host ready', function () {
    Bus::fake();
    fakeNamespaceApi();
    $server = makeHost();

    (new ProvisionServerlessHostJob($server->id))->handle();

    $server->refresh();
    expect($server->status)->toBe(Server::STATUS_READY);
    expect($server->meta['digitalocean_functions']['api_host'])->toBe('https://faas-nyc1.doserverless.co');
    expect($server->meta['digitalocean_functions']['namespace'])->toBe('fn-abc123');
    expect($server->meta['digitalocean_functions']['access_key'])->toBe('abc:secret');
});

test('dispatches a deploy for each configured function', function () {
    Bus::fake();
    fakeNamespaceApi();
    $server = makeHost();

    (new ProvisionServerlessHostJob($server->id))->handle();

    Bus::assertDispatchedTimes(RunSiteDeploymentJob::class, 1);
});

test('is idempotent when namespace already provisioned', function () {
    Bus::fake();
    Http::fake();
    // any call would 200-empty; assert none happens
    $server = makeHost([
        'digitalocean_functions' => [
            'api_host' => 'https://faas-nyc1.doserverless.co',
            'namespace' => 'fn-existing',
            'access_key' => 'k:s',
        ],
    ]);

    (new ProvisionServerlessHostJob($server->id))->handle();

    Http::assertNothingSent();

    // Still redeploys the configured functions.
    Bus::assertDispatched(RunSiteDeploymentJob::class);
});

test('managed host stamps platform credentials without calling the do api', function () {
    Bus::fake();
    Http::fake();
    config([
        'serverless.managed.api_host' => 'https://faas-nyc1.doserverless.co',
        'serverless.managed.namespace' => 'fn-dply-shared',
        'serverless.managed.access_key' => 'uuid:secretkey',
    ]);

    // Managed host has no customer credential — just the managed flag.
    $server = makeHost(['serverless_managed' => true]);
    $server->update(['provider_credential_id' => null]);

    (new ProvisionServerlessHostJob($server->id))->handle();

    Http::assertNothingSent();

    $server->refresh();
    expect($server->status)->toBe(Server::STATUS_READY);
    expect($server->meta['digitalocean_functions']['namespace'])->toBe('fn-dply-shared');
    expect($server->meta['digitalocean_functions']['access_key'])->toBe('uuid:secretkey');
    Bus::assertDispatched(RunSiteDeploymentJob::class);
});

test('managed host errors when the platform namespace is not configured', function () {
    Bus::fake();
    Http::fake();
    config([
        'serverless.managed.api_host' => '',
        'serverless.managed.namespace' => '',
        'serverless.managed.access_key' => '',
    ]);

    $server = makeHost(['serverless_managed' => true]);
    $server->update(['provider_credential_id' => null]);

    (new ProvisionServerlessHostJob($server->id))->handle();

    Http::assertNothingSent();
    $server->refresh();
    expect($server->status)->toBe(Server::STATUS_ERROR);
    expect($server->meta['provision_error']['message'] ?? '')->toContain('platform DigitalOcean Functions namespace is missing');
    Bus::assertNotDispatched(RunSiteDeploymentJob::class);
});

test('marks host errored when the api call fails', function () {
    Bus::fake();
    Http::fake([
        'api.digitalocean.com/v2/functions/namespaces' => Http::response(['message' => 'nope'], 500),
    ]);
    $server = makeHost();

    (new ProvisionServerlessHostJob($server->id))->handle();

    $server->refresh();
    expect($server->status)->toBe(Server::STATUS_ERROR);
    $this->assertArrayNotHasKey('digitalocean_functions', $server->meta);
    expect($server->meta['provision_error']['stage'] ?? null)->toBe('namespace');
    expect($server->meta['provision_error']['message'] ?? '')->toContain('DigitalOcean API failed to create functions namespace: nope');
    Bus::assertNotDispatched(RunSiteDeploymentJob::class);
});

test('redacts secrets from a persisted namespace provision error', function () {
    Bus::fake();
    Http::fake([
        'api.digitalocean.com/v2/functions/namespaces' => Http::response([
            'message' => 'Unable to authenticate you token=dop_v1_supersecret',
        ], 401),
    ]);
    $server = makeHost();

    (new ProvisionServerlessHostJob($server->id))->handle();

    $server->refresh();
    $message = (string) ($server->meta['provision_error']['message'] ?? '');
    expect($message)->toContain('Unable to authenticate you')
        ->and($message)->toContain('token=[redacted]')
        ->and($message)->not->toContain('dop_v1_supersecret');
});

test('managed host re-stamps a rotated platform key over the old one', function () {
    Bus::fake();
    Http::fake();
    config([
        'serverless.managed.api_host' => 'https://faas-nyc1.doserverless.co',
        'serverless.managed.namespace' => 'fn-dply-shared',
        'serverless.managed.access_key' => 'uuid:rotated-secret',
    ]);

    // Already provisioned with the PREVIOUS key. Config is the source of truth
    // for a managed host, so the stale stamp must be overwritten — leaving it
    // in place is what made every deploy 401 after a key rotation.
    $server = makeHost([
        'serverless_managed' => true,
        'digitalocean_functions' => [
            'api_host' => 'https://faas-nyc1.doserverless.co',
            'namespace' => 'fn-dply-shared',
            'access_key' => 'uuid:stale-secret',
        ],
    ]);
    $server->update(['provider_credential_id' => null]);

    (new ProvisionServerlessHostJob($server->id))->handle();

    Http::assertNothingSent();
    $server->refresh();
    expect($server->meta['digitalocean_functions']['access_key'])->toBe('uuid:rotated-secret');
    Bus::assertDispatched(RunSiteDeploymentJob::class);
});

test('byo host keeps its own stamped key', function () {
    Bus::fake();
    Http::fake();
    config([
        'serverless.managed.api_host' => 'https://faas-nyc1.doserverless.co',
        'serverless.managed.namespace' => 'fn-dply-shared',
        'serverless.managed.access_key' => 'uuid:platform-secret',
    ]);

    // Not managed — the customer's own namespace credentials stand.
    $server = makeHost([
        'digitalocean_functions' => [
            'api_host' => 'https://faas-nyc1.doserverless.co',
            'namespace' => 'fn-customer',
            'access_key' => 'uuid:customer-secret',
        ],
    ]);

    (new ProvisionServerlessHostJob($server->id))->handle();

    $server->refresh();
    expect($server->meta['digitalocean_functions']['access_key'])->toBe('uuid:customer-secret');
    expect($server->meta['digitalocean_functions']['namespace'])->toBe('fn-customer');
});
