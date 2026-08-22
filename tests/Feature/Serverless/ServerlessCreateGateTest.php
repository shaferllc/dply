<?php

namespace Tests\Feature\Serverless\ServerlessCreateGateTest;

use App\Enums\QuotaSurface;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Serverless\Services\ServerlessCreateGate;
use App\Support\SiteCreateBlocker;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{0: Organization, 1: User}
 */
function orgWithOwner(): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $organization->users()->attach($user->id, ['role' => 'owner']);

    return [$organization, $user];
}

function functionSiteOn(Organization $organization, User $user): Site
{
    $server = Server::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS],
    ]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'status' => Site::STATUS_FUNCTIONS_ACTIVE,
        'meta' => ['runtime_profile' => 'digitalocean_functions_web', 'serverless' => []],
    ]);
}

it('passes for an org that can create', function () {
    [$organization, $user] = orgWithOwner();
    ProviderCredential::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'provider' => 'digitalocean',
    ]);

    expect(app(ServerlessCreateGate::class)->check($user, $organization, ['delivery_mode' => 'byo']))
        ->toBeNull();
});

/**
 * The bug this gate was extracted to fix.
 *
 * Functions tally into QuotaSurface::Serverless via quotaUsageBySurface(), but
 * the create path used to ask canCreateSite() — the *machine-site* ceiling,
 * which functions never increment. So max_functions was unenforced entirely
 * and an org could create without limit.
 */
it('counts functions against the function ceiling, not the machine-site one', function () {
    [$organization, $user] = orgWithOwner();
    ProviderCredential::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'provider' => 'digitalocean',
    ]);

    // Put the org on the beta envelope so the ceiling is a config value the
    // test controls, rather than whatever the default plan happens to allow.
    $organization->forceFill(['beta_joined_at' => now()])->save();
    config()->set('subscription.standard.beta.functions', 1);

    functionSiteOn($organization, $user);

    // One function exists and the ceiling is one, so the surface is full …
    expect($organization->fresh()->quotaUsage(QuotaSurface::Serverless))->toBe(1);

    $blocker = app(ServerlessCreateGate::class)->check($user->fresh(), $organization->fresh(), ['delivery_mode' => 'byo']);

    expect($blocker)->not->toBeNull()
        ->and($blocker->code)->toBe(SiteCreateBlocker::QUOTA_EXCEEDED);
});

it('blocks a member whose role cannot update the organization', function () {
    [$organization] = orgWithOwner();
    $outsider = User::factory()->create();

    $blocker = app(ServerlessCreateGate::class)->check($outsider, $organization, []);

    expect($blocker?->code)->toBe(SiteCreateBlocker::FORBIDDEN);
});

it('names an unhealthy credential rather than failing later in the provision job', function () {
    [$organization, $user] = orgWithOwner();
    $credential = ProviderCredential::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'provider' => 'digitalocean',
        'name' => 'stale',
    ]);
    $credential->forceFill(['validation_error' => 'DigitalOcean rejected this token.'])->save();

    $blocker = app(ServerlessCreateGate::class)->check($user, $organization, [
        'delivery_mode' => 'byo',
        'provider_credential_id' => $credential->id,
    ]);

    expect($blocker?->code)->toBe(SiteCreateBlocker::CREDENTIAL_UNHEALTHY)
        ->and($blocker->message)->toContain('stale');
});

it('gates the CLI surface separately from the wizard', function () {
    [$organization, $user] = orgWithOwner();
    ProviderCredential::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'provider' => 'digitalocean',
    ]);

    \Laravel\Pennant\Feature::define('surface.serverless_cli_create', false);

    // The wizard predates the flag and is not gated on it …
    expect(app(ServerlessCreateGate::class)->check($user, $organization, ['delivery_mode' => 'byo'], ServerlessCreateGate::CONTEXT_WEB))
        ->toBeNull();

    // … while the API is.
    expect(app(ServerlessCreateGate::class)->check($user, $organization, ['delivery_mode' => 'byo'], ServerlessCreateGate::CONTEXT_API)?->code)
        ->toBe(SiteCreateBlocker::CLI_CREATE_DISABLED);
});
