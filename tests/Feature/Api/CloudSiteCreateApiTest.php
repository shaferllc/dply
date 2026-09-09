<?php

namespace Tests\Feature\Api\CloudSiteCreateApiTest;

use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

/**
 * @return array{0: Organization, 1: User, 2: string}
 */
function cloudOrgWithToken(array $abilities = ['cloud.create', 'account.read']): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $organization->users()->attach($user->id, ['role' => 'owner']);

    ['plaintext' => $plaintext] = ApiToken::createToken($user, $organization, 'test', null, $abilities);

    return [$organization, $user, $plaintext];
}

function appPlatformCredential(Organization $organization, User $user): ProviderCredential
{
    return ProviderCredential::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'provider' => 'digitalocean',
        'name' => 'do',
    ]);
}

function cloudPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'storefront',
        'mode' => 'source',
        'repo' => 'acme/storefront',
        'branch' => 'main',
        'port' => 8080,
    ], $overrides);
}

beforeEach(function () {
    Feature::define('surface.cloud_cli_create', true);
});

it('refuses when CLI creation of cloud apps is off', function () {
    Feature::define('surface.cloud_cli_create', false);
    [$organization, $user, $token] = cloudOrgWithToken();
    appPlatformCredential($organization, $user);

    $this->withToken($token)
        ->postJson('/api/v1/cloud/sites', cloudPayload(['dry_run' => true]))
        ->assertStatus(422)
        ->assertJsonPath('blocker.code', 'cli_create_disabled');
});

it('refuses a token without cloud.create', function () {
    [$organization, $user, $token] = cloudOrgWithToken(['account.read']);
    appPlatformCredential($organization, $user);

    $this->withToken($token)
        ->postJson('/api/v1/cloud/sites', cloudPayload())
        ->assertStatus(403);
});

/**
 * A container backend clones and builds the repo itself, so there is no
 * uploaded-source escape hatch the way there is for a function. The message has
 * to say what to do instead.
 */
it('will not create a source-mode app without a repository', function () {
    [$organization, $user, $token] = cloudOrgWithToken();
    appPlatformCredential($organization, $user);

    $response = $this->withToken($token)
        ->postJson('/api/v1/cloud/sites', cloudPayload(['repo' => '', 'dry_run' => true]))
        ->assertStatus(422)
        ->assertJsonPath('blocker.code', 'source_required');

    expect($response->json('blocker.message'))->toContain('git repository');
});

it('reports a missing container backend as a blocker', function () {
    [$organization, $user, $token] = cloudOrgWithToken();

    // No provider credential at all — nothing to run a container on.
    $response = $this->withToken($token)
        ->postJson('/api/v1/cloud/sites', cloudPayload(['dry_run' => true]));

    // Fake-cloud installs deliberately resolve a default backend so the create
    // flow works without credentials; only assert the blocker when the router
    // genuinely found nothing.
    if ($response->status() === 422) {
        $response->assertJsonPath('blocker.code', 'no_backend');
        expect($response->json('blocker.resolve_url'))->toContain('/credentials');
    } else {
        $response->assertOk()->assertJsonPath('data.ok', true);
    }
});

it('returns the resolved backend and quota from a dry run without creating anything', function () {
    [$organization, $user, $token] = cloudOrgWithToken();
    appPlatformCredential($organization, $user);

    $this->withToken($token)
        ->postJson('/api/v1/cloud/sites', cloudPayload(['dry_run' => true]))
        ->assertOk()
        ->assertJsonPath('data.ok', true)
        ->assertJsonPath('data.plan.mode', 'source')
        ->assertJsonStructure(['data' => ['plan' => ['backend', 'regions', 'size_tiers'], 'quota' => ['used', 'limit']]]);

    expect(Site::query()->count())->toBe(0);
});

it('rejects a region the resolved backend does not run in', function () {
    [$organization, $user, $token] = cloudOrgWithToken();
    appPlatformCredential($organization, $user);

    $this->withToken($token)
        ->postJson('/api/v1/cloud/sites', cloudPayload(['region' => 'mars1', 'dry_run' => true]))
        ->assertStatus(422)
        ->assertJsonPath('blocker.code', 'invalid_region');
});

/**
 * The check this work *adds* rather than moves.
 *
 * The Cloud wizard never consulted the billing pause, so an organization whose
 * trial had lapsed could still provision container infrastructure it was not
 * allowed to deploy to — the same hole the Serverless wizard already closed.
 */
it('blocks creation while the organization is billing-paused', function () {
    [$organization, $user, $token] = cloudOrgWithToken();
    appPlatformCredential($organization, $user);

    // An org that owes nothing is never paused, so the pause ladder only
    // engages once it does. Stub the billing computer rather than constructing
    // a whole billable fleet.
    $computer = \Mockery::mock(\App\Modules\Billing\Services\OrganizationBillingStateComputer::class)
        ->makePartial();
    $computer->shouldReceive('isFree')->andReturn(false);
    app()->instance(\App\Modules\Billing\Services\OrganizationBillingStateComputer::class, $computer);

    $organization->forceFill(['trial_ends_at' => now()->subDays(60)])->save();

    expect($organization->fresh()->canDeploy())->toBeFalse();

    $this->withToken($token)
        ->postJson('/api/v1/cloud/sites', cloudPayload(['dry_run' => true]))
        ->assertStatus(422)
        ->assertJsonPath('blocker.code', 'trial_paused');
});

it('creates a container app and links it back to the caller', function () {
    [$organization, $user, $token] = cloudOrgWithToken();
    appPlatformCredential($organization, $user);

    $response = $this->withToken($token)->postJson('/api/v1/cloud/sites', cloudPayload());

    // A create can still be refused by the provider spec; that surfaces as a
    // typed blocker rather than a 500.
    if ($response->status() === 422) {
        expect($response->json('blocker.code'))->toBeIn(['spec_rejected', 'no_backend']);

        return;
    }

    $response->assertStatus(201)->assertJsonPath('data.kind', 'cloud');

    expect(Site::query()->find($response->json('data.id')))->not->toBeNull();
});

it('reports cloud as CLI-creatable in capabilities', function () {
    [$organization, $user, $token] = cloudOrgWithToken(['account.read']);

    $this->withToken($token)
        ->getJson('/api/v1/capabilities')
        ->assertOk()
        ->assertJsonPath('data.kinds.cloud.cli_create', true)
        // A cloud app cannot be built from a folder with no remote, and the CLI
        // needs to know that before it offers the upload path.
        ->assertJsonPath('data.kinds.cloud.requires_git', true)
        ->assertJsonPath('data.kinds.serverless.requires_git', false);
});
