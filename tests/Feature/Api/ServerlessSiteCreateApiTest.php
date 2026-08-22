<?php

namespace Tests\Feature\Api\ServerlessSiteCreateApiTest;

use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\User;
use App\Modules\Serverless\Actions\CreateServerlessFunction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;

uses(RefreshDatabase::class);

/**
 * @return array{0: Organization, 1: User, 2: string}
 */
function createOrgWithToken(array $abilities = ['serverless.create', 'serverless.read', 'account.read']): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $organization->users()->attach($user->id, ['role' => 'owner']);

    ['plaintext' => $plaintext] = ApiToken::createToken($user, $organization, 'test', null, $abilities);

    return [$organization, $user, $plaintext];
}

function healthyCredential(Organization $organization, User $user): ProviderCredential
{
    return ProviderCredential::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'provider' => 'digitalocean',
        'name' => 'prod',
    ]);
}

/** The payload `dply init` sends for an ordinary git-source function. */
function initPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'checkout',
        'source_kind' => 'git',
        'repo' => 'acme/checkout',
        'branch' => 'main',
        'region' => 'nyc1',
        'delivery_mode' => 'byo',
    ], $overrides);
}

it('refuses to create when the CLI-create surface is off, and says so', function () {
    Feature::define('surface.serverless_cli_create', false);
    [$organization, $user, $token] = createOrgWithToken();
    healthyCredential($organization, $user);

    $this->withToken($token)
        ->postJson('/api/v1/serverless/sites', initPayload(['dry_run' => true]))
        ->assertStatus(422)
        ->assertJsonPath('blocker.code', 'cli_create_disabled');
});

it('reports a missing DigitalOcean credential as a blocker with somewhere to fix it', function () {
    [$organization, $user, $token] = createOrgWithToken();

    $response = $this->withToken($token)
        ->postJson('/api/v1/serverless/sites', initPayload(['dry_run' => true]))
        ->assertStatus(422)
        ->assertJsonPath('blocker.code', 'no_provider_credential');

    // The whole point of a typed blocker: the CLI can open the page that
    // clears it rather than making the user start over.
    expect($response->json('blocker.resolve_url'))->toContain('/credentials');
});

it('rejects a region the instance does not offer', function () {
    [$organization, $user, $token] = createOrgWithToken();
    healthyCredential($organization, $user);

    $this->withToken($token)
        ->postJson('/api/v1/serverless/sites', initPayload(['region' => 'mars1', 'dry_run' => true]))
        ->assertStatus(422)
        ->assertJsonPath('blocker.code', 'invalid_region');
});

it('requires a repository for a git source and points at the alternative', function () {
    [$organization, $user, $token] = createOrgWithToken();
    healthyCredential($organization, $user);

    $this->withToken($token)
        ->postJson('/api/v1/serverless/sites', initPayload(['repo' => '', 'dry_run' => true]))
        ->assertStatus(422)
        ->assertJsonPath('blocker.code', 'source_required');
});

it('will not let a token without serverless.create provision anything', function () {
    [$organization, $user, $token] = createOrgWithToken(['serverless.read', 'serverless.write']);
    healthyCredential($organization, $user);

    // serverless.write reconfigures a function that exists; it must not also
    // mean "provision a billable namespace".
    $this->withToken($token)
        ->postJson('/api/v1/serverless/sites', initPayload())
        ->assertStatus(403);
});

it('creates the function and links it back to the caller', function () {
    [$organization, $user, $token] = createOrgWithToken();
    healthyCredential($organization, $user);

    $response = $this->withToken($token)
        ->postJson('/api/v1/serverless/sites', initPayload())
        ->assertStatus(201)
        ->assertJsonPath('data.kind', 'serverless')
        ->assertJsonPath('data.name', 'checkout')
        ->assertJsonPath('data.source_kind', 'git');

    $site = Site::query()->find($response->json('data.id'));

    expect($site)->not->toBeNull()
        ->and($site->status)->toBe(Site::STATUS_FUNCTIONS_CONFIGURED)
        ->and($site->organization_id)->toBe($organization->id);
});

it('replays the original create for a repeated idempotency key', function () {
    [$organization, $user, $token] = createOrgWithToken();
    healthyCredential($organization, $user);

    $first = $this->withToken($token)
        ->withHeader('Idempotency-Key', 'init-abc')
        ->postJson('/api/v1/serverless/sites', initPayload())
        ->assertStatus(201);

    // A dropped response must not provision a second billable namespace.
    $second = $this->withToken($token)
        ->withHeader('Idempotency-Key', 'init-abc')
        ->postJson('/api/v1/serverless/sites', initPayload())
        ->assertStatus(200)
        ->assertJsonPath('replayed', true);

    expect($second->json('data.id'))->toBe($first->json('data.id'));
    expect(Site::query()->count())->toBe(1);
});

it('stores an uploaded .env in the encrypted column rather than anywhere on disk', function () {
    [$organization, $user, $token] = createOrgWithToken();
    healthyCredential($organization, $user);

    $response = $this->withToken($token)
        ->postJson('/api/v1/serverless/sites', initPayload([
            'env_file_content' => "APP_KEY=base64:hunter2\nDB_PASSWORD=swordfish\n",
        ]))
        ->assertStatus(201);

    $site = Site::query()->find($response->json('data.id'));

    expect($site->env_file_content)->toContain('DB_PASSWORD=swordfish');

    // Encrypted at rest — the raw column must not hold the secret.
    $raw = (string) \DB::table('sites')->where('id', $site->id)->value('env_file_content');
    expect($raw)->not->toContain('swordfish');

    // And it never appears in the response.
    expect(json_encode($response->json()))->not->toContain('swordfish');
});

it('records the monorepo subdirectory the web wizard cannot express', function () {
    [$organization, $user, $token] = createOrgWithToken();
    healthyCredential($organization, $user);

    $response = $this->withToken($token)
        ->postJson('/api/v1/serverless/sites', initPayload(['repository_subdirectory' => 'apps/api']))
        ->assertStatus(201);

    $site = Site::query()->find($response->json('data.id'));

    expect($site->serverlessConfig()['repository_subdirectory'])->toBe('apps/api');
});

it('creates an upload-source function with no repository at all', function () {
    [$organization, $user, $token] = createOrgWithToken();
    healthyCredential($organization, $user);

    $response = $this->withToken($token)
        ->postJson('/api/v1/serverless/sites', initPayload([
            'source_kind' => 'upload',
            'repo' => '',
        ]))
        ->assertStatus(201)
        ->assertJsonPath('data.source_kind', 'upload');

    $site = Site::query()->find($response->json('data.id'));

    expect($site->serverlessConfig()['source_kind'])->toBe('upload');
    // Push-to-deploy is structurally impossible here, and it is said plainly
    // rather than silently omitted.
    expect($response->json('data.push_to_deploy.message'))->toContain('no remote');
});

it('deletes a function that never deployed — init\'s undo', function () {
    [$organization, $user, $token] = createOrgWithToken();
    healthyCredential($organization, $user);

    $created = $this->withToken($token)
        ->postJson('/api/v1/serverless/sites', initPayload())
        ->assertStatus(201);

    $this->withToken($token)
        ->deleteJson('/api/v1/serverless/sites/'.$created->json('data.id'))
        ->assertOk()
        ->assertJsonPath('data.deleted', true);

    expect(Site::query()->find($created->json('data.id')))->toBeNull();
});

it('refuses to delete a function that has deployed successfully', function () {
    [$organization, $user, $token] = createOrgWithToken();
    healthyCredential($organization, $user);

    $created = $this->withToken($token)
        ->postJson('/api/v1/serverless/sites', initPayload())
        ->assertStatus(201);

    $siteId = $created->json('data.id');

    $site = Site::query()->find($siteId);
    SiteDeployment::query()->create([
        'site_id' => $siteId,
        'project_id' => $site->project_id,
        'status' => SiteDeployment::STATUS_SUCCESS,
        'trigger' => SiteDeployment::TRIGGER_MANUAL,
    ]);

    // This is what makes a general delete endpoint unnecessary: the undo can
    // never destroy something that served a request.
    $this->withToken($token)
        ->deleteJson('/api/v1/serverless/sites/'.$siteId)
        ->assertStatus(409);

    expect(Site::query()->find($siteId))->not->toBeNull();
});

it('exposes provisioning state so the CLI can follow the phase before a deploy exists', function () {
    [$organization, $user, $token] = createOrgWithToken();
    $credential = healthyCredential($organization, $user);

    $site = app(CreateServerlessFunction::class)->handle($user, $organization, [
        'name' => 'checkout',
        'repo' => 'acme/checkout',
        'branch' => 'main',
        'delivery_mode' => 'byo',
        'provider_credential_id' => $credential->id,
        'region' => 'nyc1',
    ]);

    $site->server->forceFill([
        'status' => Server::STATUS_ERROR,
        'meta' => array_merge($site->server->meta ?? [], ['provision_error' => 'DigitalOcean rejected the token.']),
    ])->save();

    $this->withToken($token)
        ->getJson("/api/v1/serverless/sites/{$site->id}")
        ->assertOk()
        ->assertJsonPath('data.provision.failed', true)
        ->assertJsonPath('data.provision.error', 'DigitalOcean rejected the token.');
});

/**
 * `cli_create_supported` vs `cli_create`: the CLI has to tell "this dply version
 * has no endpoint for that kind" apart from "an operator has not switched it on
 * here", because only the second is something the user can fix.
 */
it('separates a kind it cannot create from one that is merely switched off', function () {
    [$organization, $user, $token] = createOrgWithToken();

    Feature::define('surface.serverless_cli_create', false);

    $this->withToken($token)
        ->getJson('/api/v1/capabilities')
        ->assertOk()
        ->assertJsonPath('data.kinds.serverless.cli_create_supported', true)
        ->assertJsonPath('data.kinds.serverless.cli_create', false)
        ->assertJsonPath('data.kinds.serverless.cli_create_flag', 'FEATURE_SURFACE_SERVERLESS_CLI_CREATE')
        // Edge genuinely has no create endpoint yet.
        ->assertJsonPath('data.kinds.edge.cli_create_supported', false);
});

it('tells the CLI what this instance offers', function () {
    [$organization, $user, $token] = createOrgWithToken();

    $this->withToken($token)
        ->getJson('/api/v1/capabilities')
        ->assertOk()
        ->assertJsonPath('data.kinds.serverless.cli_create', true)
        ->assertJsonPath('data.serverless.default_region', 'nyc1')
        ->assertJsonStructure(['data' => ['serverless' => ['regions', 'upload' => ['max_bytes']]]]);
});
