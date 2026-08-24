<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations\OrganizationPeopleTest;

use App\Livewire\Organizations\Members as OrganizationsPeople;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Covers what the retired Teams page used to own, now that teams are a filter
 * over the People directory rather than a page of their own.
 */
function orgWithOwner(): array
{
    $owner = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    return [$owner, $org];
}

test('the team rail filters the directory down to that team', function () {
    [$owner, $org] = orgWithOwner();

    $onTeam = User::factory()->create(['name' => 'Sofia Braun']);
    $offTeam = User::factory()->create(['name' => 'Nils Berg']);
    $org->users()->attach($onTeam->id, ['role' => 'member']);
    $org->users()->attach($offTeam->id, ['role' => 'member']);

    $team = Team::factory()->create(['organization_id' => $org->id, 'name' => 'Platform']);
    $team->users()->attach($onTeam->id, ['role' => 'member']);

    Livewire::actingAs($owner)
        ->test(OrganizationsPeople::class, ['organization' => $org])
        ->assertSee('Sofia Braun')
        ->assertSee('Nils Berg')
        ->call('selectTeam', (string) $team->id)
        ->assertSet('teamFilter', (string) $team->id)
        ->assertSee('Sofia Braun')
        ->assertDontSee('Nils Berg');
});

test('an unknown team id falls back to showing everyone', function () {
    [$owner, $org] = orgWithOwner();

    Livewire::actingAs($owner)
        ->test(OrganizationsPeople::class, ['organization' => $org])
        ->call('selectTeam', 'not-a-team')
        ->assertSet('teamFilter', '');
});

test('the team chip toggles membership both ways', function () {
    [$owner, $org] = orgWithOwner();

    $member = User::factory()->create();
    $org->users()->attach($member->id, ['role' => 'member']);
    $team = Team::factory()->create(['organization_id' => $org->id]);

    $component = Livewire::actingAs($owner)->test(OrganizationsPeople::class, ['organization' => $org]);

    $component->call('toggleTeamMembership', (string) $team->id, (string) $member->id);
    expect($team->users()->where('user_id', $member->id)->exists())->toBeTrue();

    $component->call('toggleTeamMembership', (string) $team->id, (string) $member->id);
    expect($team->users()->where('user_id', $member->id)->exists())->toBeFalse();
});

test('a non-member is never attached to a team', function () {
    [$owner, $org] = orgWithOwner();

    $outsider = User::factory()->create();
    $team = Team::factory()->create(['organization_id' => $org->id]);

    Livewire::actingAs($owner)
        ->test(OrganizationsPeople::class, ['organization' => $org])
        ->call('toggleTeamMembership', (string) $team->id, (string) $outsider->id);

    expect($team->users()->where('user_id', $outsider->id)->exists())->toBeFalse();
});

test('creating a team selects it, renaming keeps it', function () {
    [$owner, $org] = orgWithOwner();

    $component = Livewire::actingAs($owner)
        ->test(OrganizationsPeople::class, ['organization' => $org])
        ->call('openTeamModal')
        ->set('team_name', 'Platform')
        ->call('saveTeam')
        ->assertHasNoErrors();

    // A new organization already has its default team, so match on the name.
    $team = $org->teams()->where('name', 'Platform')->firstOrFail();
    $component->assertSet('teamFilter', (string) $team->id);

    $component->call('openTeamModal', (string) $team->id)
        ->assertSet('team_name', 'Platform')
        ->set('team_name', 'Platform & Infra')
        ->call('saveTeam')
        ->assertHasNoErrors();

    expect($team->fresh()->name)->toBe('Platform & Infra')
        ->and($org->teams()->where('name', 'Platform')->exists())->toBeFalse();
});

test('deleting the selected team clears the filter', function () {
    [$owner, $org] = orgWithOwner();

    $team = Team::factory()->create(['organization_id' => $org->id]);

    Livewire::actingAs($owner)
        ->test(OrganizationsPeople::class, ['organization' => $org])
        ->call('selectTeam', (string) $team->id)
        ->call('deleteTeam', (string) $team->id)
        ->assertSet('teamFilter', '');

    expect($org->teams()->whereKey($team->id)->exists())->toBeFalse();
});

test('inviting an existing member with a team attaches them instead of mailing', function () {
    [$owner, $org] = orgWithOwner();

    $member = User::factory()->create(['email' => 'sofia@acme.io']);
    $org->users()->attach($member->id, ['role' => 'member']);
    $team = Team::factory()->create(['organization_id' => $org->id]);

    Livewire::actingAs($owner)
        ->test(OrganizationsPeople::class, ['organization' => $org])
        ->set('invite_email', 'sofia@acme.io')
        ->set('invite_team_id', (string) $team->id)
        ->call('inviteMember')
        ->assertHasNoErrors();

    expect($team->users()->where('user_id', $member->id)->exists())->toBeTrue()
        ->and($org->invitations()->count())->toBe(0);
});

test('inviting an existing member without a team is rejected', function () {
    [$owner, $org] = orgWithOwner();

    $member = User::factory()->create(['email' => 'sofia@acme.io']);
    $org->users()->attach($member->id, ['role' => 'member']);

    Livewire::actingAs($owner)
        ->test(OrganizationsPeople::class, ['organization' => $org])
        ->set('invite_email', 'sofia@acme.io')
        ->call('inviteMember')
        ->assertHasErrors('invite_email');
});

test('the retired teams URL redirects to People', function () {
    [$owner, $org] = orgWithOwner();

    $this->actingAs($owner)
        ->get(route('organizations.teams', $org))
        ->assertRedirect(route('organizations.members', $org));
});

test('a team invitation shows under that team and under all people', function () {
    [$owner, $org] = orgWithOwner();

    $team = Team::factory()->create(['organization_id' => $org->id, 'name' => 'Platform']);
    $other = Team::factory()->create(['organization_id' => $org->id, 'name' => 'Growth']);

    Livewire::actingAs($owner)
        ->test(OrganizationsPeople::class, ['organization' => $org])
        ->set('invite_email', 'ravi@contractor.dev')
        ->set('invite_team_id', (string) $team->id)
        ->call('inviteMember')
        ->assertHasNoErrors()
        ->assertSee('ravi@contractor.dev')
        ->call('selectTeam', (string) $team->id)
        ->assertSee('ravi@contractor.dev')
        ->call('selectTeam', (string) $other->id)
        ->assertDontSee('ravi@contractor.dev');
});
