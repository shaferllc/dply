<?php

namespace Tests\Feature;

use App\Livewire\Settings\WebserverTemplates;
use App\Models\Organization;
use App\Models\User;
use App\Models\WebserverTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WebserverTemplatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_view_webserver_templates(): void
    {
        $org = Organization::factory()->create();

        $this->get(route('organizations.webserver-templates', $org))->assertRedirect();
    }

    public function test_org_member_can_view_webserver_templates_page(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'member']);

        Livewire::actingAs($user)
            ->test(WebserverTemplates::class, ['organization' => $org])
            ->assertOk()
            ->assertSee('Webserver templates');
    }

    public function test_org_admin_can_create_template(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'admin']);

        $content = "# Dply webserver template — do not remove\nserver { listen 80; }\n";

        Livewire::actingAs($user)
            ->test(WebserverTemplates::class, ['organization' => $org])
            ->set('label', 'My default')
            ->set('content', $content)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('webserver_templates', [
            'organization_id' => $org->id,
            'label' => 'My default',
        ]);
    }

    public function test_org_member_cannot_save_template(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'member']);

        $content = "# Dply webserver template — do not remove\nserver { listen 80; }\n";

        Livewire::actingAs($user)
            ->test(WebserverTemplates::class, ['organization' => $org])
            ->set('label', 'X')
            ->set('content', $content)
            ->call('save')
            ->assertForbidden();
    }

    public function test_org_admin_can_delete_template(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'admin']);

        $template = WebserverTemplate::factory()->create(['organization_id' => $org->id]);

        Livewire::actingAs($user)
            ->test(WebserverTemplates::class, ['organization' => $org])
            ->call('delete', $template->id);

        $this->assertDatabaseMissing('webserver_templates', ['id' => $template->id]);
    }
}
