<?php

namespace Tests\Feature;

use App\Livewire\Scripts\Create;
use App\Livewire\Scripts\Marketplace;
use App\Models\Organization;
use App\Models\Script;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ScriptsTest extends TestCase
{
    use RefreshDatabase;

    protected function ownerWithOrg(): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        return $user;
    }

    public function test_guest_cannot_view_scripts(): void
    {
        $this->get(route('scripts.index'))->assertRedirect();
    }

    public function test_member_can_view_scripts_index(): void
    {
        $user = $this->ownerWithOrg();

        $this->actingAs($user)
            ->get(route('scripts.index'))
            ->assertOk()
            ->assertSee('Scripts', false);
    }

    public function test_member_can_create_script(): void
    {
        $user = $this->ownerWithOrg();
        $org = $user->currentOrganization();

        Livewire::actingAs($user)
            ->test(Create::class)
            ->set('name', 'My provisioner')
            ->set('content', "#!/bin/bash\necho ok\n")
            ->set('run_as_user', '')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('scripts', [
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'name' => 'My provisioner',
        ]);
    }

    public function test_deployer_cannot_open_scripts_index(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'deployer']);
        session(['current_organization_id' => $org->id]);

        $this->actingAs($user)
            ->get(route('scripts.index'))
            ->assertForbidden();
    }

    public function test_marketplace_clone_creates_marketplace_sourced_script(): void
    {
        $user = $this->ownerWithOrg();
        $org = $user->currentOrganization();

        Livewire::actingAs($user)
            ->test(Marketplace::class)
            ->call('clonePreset', 'disk-usage-summary')
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('scripts', [
            'organization_id' => $org->id,
            'source' => Script::SOURCE_MARKETPLACE,
            'marketplace_key' => 'disk-usage-summary',
        ]);
    }
}
