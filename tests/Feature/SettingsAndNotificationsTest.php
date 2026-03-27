<?php

namespace Tests\Feature;

use App\Livewire\Organizations\Show as OrganizationsShow;
use App\Livewire\Settings\Hub as SettingsHub;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsAndNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_hub_is_reachable_for_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSee('Account & organization');
    }

    public function test_settings_hub_livewire_renders(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(SettingsHub::class)
            ->assertOk();
    }

    public function test_docs_source_control_renders_markdown(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('docs.source-control'))
            ->assertOk();
    }

    public function test_org_admin_can_disable_deploy_email_notifications(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        Livewire::actingAs($user)
            ->test(OrganizationsShow::class, ['organization' => $org])
            ->set('deploy_email_notifications_enabled', false);

        $this->assertDatabaseHas('organizations', [
            'id' => $org->id,
            'deploy_email_notifications_enabled' => false,
        ]);
    }
}
