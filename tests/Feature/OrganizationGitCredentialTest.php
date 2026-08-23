<?php

namespace Tests\Feature\OrganizationGitCredentialTest;

use App\Livewire\Credentials\Index as CredentialsIndex;
use App\Models\GitProviderToken;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Modules\SourceControl\Services\GitIdentityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function orgWithOwner(string $role = 'owner'): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => $role]);
    session(['current_organization_id' => $org->id]);

    return [$user, $org];
}

function orgToken(Organization $org, array $attributes = []): GitProviderToken
{
    return GitProviderToken::query()->create(array_merge([
        'organization_id' => $org->id,
        'provider' => 'github',
        'nickname' => 'deploy-bot',
        'access_token' => 'ghp_orgtokenorgtokenorgtoken',
        'last_validated_at' => now(),
    ], $attributes));
}

test('an org token deploys a site whose owner has no git credential of their own', function () {
    [$owner, $org] = orgWithOwner();
    $site = Site::factory()->create(['user_id' => $owner->id, 'organization_id' => $org->id]);
    $token = orgToken($org);

    $identity = app(GitIdentityResolver::class)->forSite($site->fresh(), $owner, 'github');

    expect($identity)->not->toBeNull();
    expect($identity->id())->toBe((string) $token->id);
});

test('an org token outranks the site owner\'s personal token', function () {
    [$owner, $org] = orgWithOwner();
    $site = Site::factory()->create(['user_id' => $owner->id, 'organization_id' => $org->id]);

    GitProviderToken::query()->create([
        'user_id' => $owner->id,
        'provider' => 'github',
        'nickname' => 'my-laptop',
        'access_token' => 'ghp_personalpersonalpersonal',
        'last_validated_at' => now(),
    ]);
    $shared = orgToken($org);

    // The shared credential is the one that survives this person leaving, so it
    // wins when no identity is pinned. See the ADR, decision 3.
    $identity = app(GitIdentityResolver::class)->forSite($site->fresh(), $owner, 'github');

    expect($identity->id())->toBe((string) $shared->id);
});

test('a rejected org token falls back to the owner\'s working personal token', function () {
    [$owner, $org] = orgWithOwner();
    $site = Site::factory()->create(['user_id' => $owner->id, 'organization_id' => $org->id]);

    orgToken($org, ['validation_error' => 'Bad credentials']);
    $personal = GitProviderToken::query()->create([
        'user_id' => $owner->id,
        'provider' => 'github',
        'nickname' => 'my-laptop',
        'access_token' => 'ghp_personalpersonalpersonal',
        'last_validated_at' => now(),
    ]);

    $identity = app(GitIdentityResolver::class)->forSite($site->fresh(), $owner, 'github');

    expect($identity->id())->toBe((string) $personal->id);
});

test('a member of another organization cannot resolve the token', function () {
    [, $org] = orgWithOwner();
    $token = orgToken($org);

    $outsider = User::factory()->create();
    $otherOrg = Organization::factory()->create();
    $otherOrg->users()->attach($outsider->id, ['role' => 'owner']);

    expect(app(GitIdentityResolver::class)->forId($outsider, (string) $token->id))->toBeNull();
});

test('any member of the owning organization can resolve it', function () {
    [, $org] = orgWithOwner();
    $token = orgToken($org);

    $teammate = User::factory()->create();
    $org->users()->attach($teammate->id, ['role' => 'member']);

    expect(app(GitIdentityResolver::class)->forId($teammate, (string) $token->id)?->id())
        ->toBe((string) $token->id);
});

test('the database rejects a token with two owners or none', function () {
    [$user, $org] = orgWithOwner();

    expect(fn () => DB::table('git_provider_tokens')->insert([
        'id' => (string) \Illuminate\Support\Str::ulid(),
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'github',
        'access_token' => 'x',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(\Illuminate\Database\QueryException::class);

    expect(fn () => DB::table('git_provider_tokens')->insert([
        'id' => (string) \Illuminate\Support\Str::ulid(),
        'user_id' => null,
        'organization_id' => null,
        'provider' => 'github',
        'access_token' => 'x',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

test('org tokens are listed on the org credentials page and removable by an admin', function () {
    [$user, $org] = orgWithOwner();
    $token = orgToken($org);

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class, ['organization' => $org])
        ->assertSee('deploy-bot')
        ->call('promptDeleteGitToken', (string) $token->id)
        ->assertSet('confirmActionModalMethod', 'deleteGitToken')
        ->call('deleteGitToken', (string) $token->id)
        ->assertDontSee('deploy-bot');

    $this->assertDatabaseMissing('git_provider_tokens', ['id' => $token->id]);
});

test('a personal token never appears on the org credentials page', function () {
    [$user, $org] = orgWithOwner();
    GitProviderToken::query()->create([
        'user_id' => $user->id,
        'provider' => 'github',
        'nickname' => 'my-laptop',
        'access_token' => 'ghp_personalpersonalpersonal',
    ]);

    Livewire::actingAs($user)
        ->test(CredentialsIndex::class, ['organization' => $org])
        ->assertDontSee('my-laptop');
});

test('an admin of another org cannot delete the token', function () {
    [, $org] = orgWithOwner();
    $token = orgToken($org);

    $outsider = User::factory()->create();
    $otherOrg = Organization::factory()->create();
    $otherOrg->users()->attach($outsider->id, ['role' => 'owner']);

    expect(fn () => Livewire::actingAs($outsider)
        ->test(CredentialsIndex::class, ['organization' => $otherOrg])
        ->call('deleteGitToken', (string) $token->id)
    )->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    $this->assertDatabaseHas('git_provider_tokens', ['id' => $token->id]);
});

test('the add-token dialog renders for every provider on the org page', function () {
    [$user, $org] = orgWithOwner();

    // The form reads id, name AND host; a hand-built provider array here once
    // omitted host and blew up only when the form was actually opened.
    foreach (['github', 'gitlab', 'bitbucket'] as $provider) {
        Livewire::actingAs($user)
            ->test(CredentialsIndex::class, ['organization' => $org])
            ->call('startAddOrganizationPat', $provider)
            ->assertSet('addingPatProvider', $provider)
            // The dialog is driven by state, not an event, so its heading is in
            // the rendered output as soon as the provider is set.
            ->assertSee('Add a '.\App\Models\GitProviderToken::providerDescriptor($provider)['name'].' token')
            ->assertSee('Owned by the organization')
            ->assertOk();
    }
});

test('a non-admin cannot open the shared token form', function () {
    [, $org] = orgWithOwner();
    $member = User::factory()->create();
    $org->users()->attach($member->id, ['role' => 'member']);

    Livewire::actingAs($member)
        ->test(CredentialsIndex::class, ['organization' => $org])
        ->call('startAddOrganizationPat', 'github')
        ->assertSet('addingPatProvider', null);
});
