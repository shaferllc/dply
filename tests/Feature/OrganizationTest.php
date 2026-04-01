<?php

namespace Tests\Feature;

use App\Livewire\Organizations\Create as OrganizationsCreate;
use App\Livewire\Organizations\Index as OrganizationsIndex;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OrganizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_organizations_index_is_displayed(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        $response = $this->actingAs($user)->get(route('organizations.index'));

        $response->assertOk();
        $response->assertSee($org->name);
    }

    public function test_organizations_index_prompts_create_when_empty(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('organizations.index'));

        $response->assertOk();
        $response->assertSee('Create your first organization');
    }

    public function test_organization_create_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('organizations.create'));

        $response->assertOk();
        $response->assertSee('New organization');
    }

    public function test_organization_can_be_created(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(OrganizationsCreate::class)
            ->set('name', 'Acme Corp')
            ->call('store')
            ->assertRedirect();

        $this->assertDatabaseHas('organizations', ['name' => 'Acme Corp']);
        $org = Organization::where('name', 'Acme Corp')->first();
        $this->assertTrue($org->hasMember($user));
        $this->assertSame('owner', $org->users()->where('user_id', $user->id)->first()->pivot->role);
    }

    public function test_organization_show_is_displayed_for_member(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'member']);

        $response = $this->actingAs($user)->get(route('organizations.show', $org));

        $response->assertOk();
        $response->assertSee($org->name);
        $response->assertSee('Members');
    }

    public function test_organization_show_uses_universal_notification_language_for_admins(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        $response = $this->actingAs($user)->get(route('organizations.show', $org));

        $response->assertOk();
        $response->assertSee('Notification destinations & preferences');
        $response->assertSee('Webhook destinations');
        $response->assertSee('Manage saved destinations');
    }

    public function test_organization_show_returns_403_for_non_member(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();

        $response = $this->actingAs($user)->get(route('organizations.show', $org));

        $response->assertForbidden();
    }

    public function test_organization_switch_updates_session(): void
    {
        $user = User::factory()->create();
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();
        $org1->users()->attach($user->id, ['role' => 'owner']);
        $org2->users()->attach($user->id, ['role' => 'member']);

        Livewire::actingAs($user)
            ->test(OrganizationsIndex::class)
            ->call('switchOrganization', $org2->id)
            ->assertRedirect();

        $this->assertEquals((string) $org2->id, session('current_organization_id'));
    }

    public function test_organization_switch_returns_403_for_non_member(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        session()->forget('current_organization_id');

        try {
            Livewire::actingAs($user)
                ->test(OrganizationsIndex::class)
                ->call('switchOrganization', $org->id);
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());

            return;
        }
        $this->assertNotEquals((string) $org->id, session('current_organization_id'), 'Non-member must not be able to switch to organization.');
    }
}
