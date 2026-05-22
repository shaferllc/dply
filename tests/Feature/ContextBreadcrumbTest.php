<?php


namespace Tests\Feature\ContextBreadcrumbTest;
use App\Livewire\Layout\ContextBreadcrumb;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('switch organization updates session and clears team', function () {
    $user = User::factory()->create();
    $org1 = Organization::factory()->create();
    $org2 = Organization::factory()->create();
    $org1->users()->attach($user->id, ['role' => 'owner']);
    $org2->users()->attach($user->id, ['role' => 'member']);
    $team = Team::factory()->create(['organization_id' => $org1->id]);

    $this->actingAs($user);
    session([
        'current_organization_id' => $org1->id,
        'current_team_id' => $team->id,
    ]);

    Livewire::test(ContextBreadcrumb::class)
        ->call('switchOrganization', $org2->id)
        ->assertRedirect();

    expect(session('current_organization_id'))->toBe($org2->id);
    expect(session('current_team_id'))->toBeNull();
});

test('switch team sets session', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'member']);
    $team = Team::factory()->create(['organization_id' => $org->id]);

    $this->actingAs($user);
    session(['current_organization_id' => $org->id]);

    Livewire::test(ContextBreadcrumb::class)
        ->call('switchTeam', $team->id)
        ->assertRedirect();

    expect(session('current_team_id'))->toBe($team->id);
});

test('switch team without id clears session', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'member']);
    $team = Team::factory()->create(['organization_id' => $org->id]);

    $this->actingAs($user);
    session([
        'current_organization_id' => $org->id,
        'current_team_id' => $team->id,
    ]);

    Livewire::test(ContextBreadcrumb::class)
        ->call('switchTeam')
        ->assertRedirect();

    expect(session('current_team_id'))->toBeNull();
});

test('switch team does not apply team from another organization', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $otherOrg = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'member']);
    $foreignTeam = Team::factory()->create(['organization_id' => $otherOrg->id]);

    $this->actingAs($user);
    session([
        'current_organization_id' => $org->id,
        'current_team_id' => null,
    ]);

    try {
        Livewire::test(ContextBreadcrumb::class)
            ->call('switchTeam', $foreignTeam->id);
    } catch (\Throwable) {
        // Livewire / Laravel may abort with an HTTP exception in some configurations.
    }

    $this->assertNotSame($foreignTeam->id, session('current_team_id'));
});

test('context breadcrumb initials', function () {
    expect(ContextBreadcrumb::initials('Acme Corp'))->toBe('AC');
    expect(ContextBreadcrumb::initials('Jane Doe'))->toBe('JD');
    expect(ContextBreadcrumb::initials('Alpha'))->toBe('AL');
});