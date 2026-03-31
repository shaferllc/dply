<?php

namespace Tests\Feature;

use App\Livewire\Layout\ContextBreadcrumb;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ContextBreadcrumbTest extends TestCase
{
    use RefreshDatabase;

    public function test_switch_organization_updates_session_and_clears_team(): void
    {
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

        $this->assertSame($org2->id, session('current_organization_id'));
        $this->assertNull(session('current_team_id'));
    }

    public function test_switch_team_sets_session(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'member']);
        $team = Team::factory()->create(['organization_id' => $org->id]);

        $this->actingAs($user);
        session(['current_organization_id' => $org->id]);

        Livewire::test(ContextBreadcrumb::class)
            ->call('switchTeam', $team->id)
            ->assertRedirect();

        $this->assertSame($team->id, session('current_team_id'));
    }

    public function test_switch_team_without_id_clears_session(): void
    {
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

        $this->assertNull(session('current_team_id'));
    }

    public function test_switch_team_does_not_apply_team_from_another_organization(): void
    {
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
    }

    public function test_context_breadcrumb_initials(): void
    {
        $this->assertSame('AC', ContextBreadcrumb::initials('Acme Corp'));
        $this->assertSame('JD', ContextBreadcrumb::initials('Jane Doe'));
        $this->assertSame('AL', ContextBreadcrumb::initials('Alpha'));
    }
}
