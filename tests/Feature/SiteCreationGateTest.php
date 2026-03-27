<?php

namespace Tests\Feature;

use App\Livewire\Sites\Show as SitesShow;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Tests\TestCase;

class SiteCreationGateTest extends TestCase
{
    use RefreshDatabase;

    private function actingInOrg(User $user, Organization $org): void
    {
        $this->actingAs($user);
        session(['current_organization_id' => $org->id]);
    }

    public function test_deployer_cannot_open_site_create_form(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'deployer']);
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        $this->actingInOrg($user, $org);

        $this->get(route('sites.create', $server))->assertForbidden();
    }

    public function test_site_create_forbidden_when_org_at_site_limit(): void
    {
        Config::set('subscription.limits.sites_free', 1);

        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        $this->actingInOrg($user, $org);

        $this->get(route('sites.create', $server))->assertForbidden();
    }

    public function test_owner_can_delete_site(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        $this->actingInOrg($user, $org);

        Livewire::actingAs($user)
            ->test(SitesShow::class, ['server' => $server, 'site' => $site])
            ->call('deleteSite')
            ->assertRedirect(route('servers.show', $server, false));

        $this->assertDatabaseMissing('sites', ['id' => $site->id]);
    }

    public function test_member_cannot_delete_site(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($owner->id, ['role' => 'owner']);
        $org->users()->attach($member->id, ['role' => 'member']);
        $server = Server::factory()->ready()->create([
            'user_id' => $owner->id,
            'organization_id' => $org->id,
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $owner->id,
            'organization_id' => $org->id,
        ]);

        $this->actingInOrg($member, $org);

        Livewire::actingAs($member)
            ->test(SitesShow::class, ['server' => $server, 'site' => $site])
            ->call('deleteSite')
            ->assertForbidden();

        $this->assertDatabaseHas('sites', ['id' => $site->id]);
    }
}
