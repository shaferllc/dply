<?php

namespace Tests\Feature\Api\ServerlessEnvApiTest;

use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A function's environment is the ordinary site env store, so `dply serverless
 * env` drives the shared `/sites/{site}/env` endpoints rather than a second
 * serverless-only surface. These pin that a functions site is actually
 * accepted there — the CLI has nowhere else to go if it isn't.
 *
 * @return array{0: Site, 1: string}
 */
function envFunctionSiteWithToken(array $abilities = ['sites.read', 'sites.write']): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $organization->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'status' => Site::STATUS_FUNCTIONS_ACTIVE,
        'meta' => ['runtime_profile' => 'digitalocean_functions_web'],
        'env_file_content' => "APP_ENV=production\nSTRIPE_KEY=sk_live_x\n",
    ]);

    ['plaintext' => $plaintext] = ApiToken::createToken($user, $organization, 'test', null, $abilities);

    return [$site, $plaintext];
}

it('lists a function site keys without leaking values', function () {
    [$site, $token] = envFunctionSiteWithToken();

    $response = $this->withToken($token)
        ->getJson("/api/v1/sites/{$site->id}/env")
        ->assertOk()
        ->assertJsonPath('data.0.key', 'APP_ENV');

    expect($response->getContent())->not->toContain('sk_live_x');
});

it('sets and removes a variable on a function site', function () {
    [$site, $token] = envFunctionSiteWithToken();

    $this->withToken($token)
        ->patchJson("/api/v1/sites/{$site->id}/env/QUEUE_CONNECTION", ['value' => 'database'])
        ->assertOk();

    expect($site->fresh()->env_file_content)->toContain('QUEUE_CONNECTION=database');

    $this->withToken($token)
        ->deleteJson("/api/v1/sites/{$site->id}/env/QUEUE_CONNECTION")
        ->assertOk();

    expect($site->fresh()->env_file_content)->not->toContain('QUEUE_CONNECTION');
});

it('reads and replaces the whole file behind sites.write', function () {
    [$site, $writeToken] = envFunctionSiteWithToken();
    [$readSite, $readToken] = envFunctionSiteWithToken(['sites.read']);

    $this->withToken($writeToken)
        ->getJson("/api/v1/sites/{$site->id}/env/content")
        ->assertOk()
        ->assertJsonPath('data.content', "APP_ENV=production\nSTRIPE_KEY=sk_live_x\n");

    $this->withToken($writeToken)
        ->putJson("/api/v1/sites/{$site->id}/env/content", ['content' => "APP_ENV=staging\n"])
        ->assertOk();

    expect($site->fresh()->env_file_content)->toContain('APP_ENV=staging')
        ->and($site->fresh()->env_file_content)->not->toContain('STRIPE_KEY');

    // Values are the secret half — a read-scoped token can list keys but not
    // pull the file.
    $this->withToken($readToken)
        ->getJson("/api/v1/sites/{$readSite->id}/env/content")
        ->assertForbidden();
});
