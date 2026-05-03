<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FleetDomainsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_all_domains_for_current_org(): void
    {
        [$user, $org] = $this->makeUserOrg();
        $server = Server::factory()->create(['organization_id' => $org->id]);
        $site = Site::factory()->create(['server_id' => $server->id, 'organization_id' => $org->id, 'name' => 'jobs']);
        $site->domains()->create(['hostname' => 'jobs.example.com', 'is_primary' => true]);
        $site->domains()->create(['hostname' => 'alias.example.com', 'is_primary' => false]);

        $response = $this->actingAs($user)->get(route('fleet.domains'));

        $response->assertOk()
            ->assertSee('jobs.example.com')
            ->assertSee('alias.example.com')
            ->assertSee('jobs');
    }

    public function test_search_narrows_to_matching_hostname(): void
    {
        [$user, $org] = $this->makeUserOrg();
        $server = Server::factory()->create(['organization_id' => $org->id]);
        $site = Site::factory()->create(['server_id' => $server->id, 'organization_id' => $org->id]);
        $site->domains()->create(['hostname' => 'jobs.example.com']);
        $site->domains()->create(['hostname' => 'careers.test.io']);

        $response = $this->actingAs($user)->get(route('fleet.domains').'?q=example');

        $response->assertOk()
            ->assertSee('jobs.example.com')
            ->assertDontSee('careers.test.io');
    }

    public function test_primary_only_filter(): void
    {
        [$user, $org] = $this->makeUserOrg();
        $server = Server::factory()->create(['organization_id' => $org->id]);
        $site = Site::factory()->create(['server_id' => $server->id, 'organization_id' => $org->id]);
        $site->domains()->create(['hostname' => 'primary.example.com', 'is_primary' => true]);
        $site->domains()->create(['hostname' => 'alias.example.com', 'is_primary' => false]);

        $response = $this->actingAs($user)->get(route('fleet.domains').'?primary_only=1');

        $response->assertOk()
            ->assertSee('primary.example.com')
            ->assertDontSee('alias.example.com');
    }

    public function test_runtime_filter(): void
    {
        [$user, $org] = $this->makeUserOrg();
        $server = Server::factory()->create(['organization_id' => $org->id]);
        $php = Site::factory()->create(['server_id' => $server->id, 'organization_id' => $org->id, 'runtime' => 'php']);
        $node = Site::factory()->create(['server_id' => $server->id, 'organization_id' => $org->id, 'runtime' => 'node']);
        $php->domains()->create(['hostname' => 'php.example.com']);
        $node->domains()->create(['hostname' => 'node.example.com']);

        $response = $this->actingAs($user)->get(route('fleet.domains').'?runtime=node');

        $response->assertOk()
            ->assertSee('node.example.com')
            ->assertDontSee('php.example.com');
    }

    public function test_does_not_show_other_org_domains(): void
    {
        [$user, $org] = $this->makeUserOrg();
        $otherOrg = Organization::factory()->create();
        $otherServer = Server::factory()->create(['organization_id' => $otherOrg->id]);
        $otherSite = Site::factory()->create(['server_id' => $otherServer->id, 'organization_id' => $otherOrg->id]);
        $otherSite->domains()->create(['hostname' => 'private.other.com']);

        $response = $this->actingAs($user)->get(route('fleet.domains'));

        $response->assertOk()
            ->assertDontSee('private.other.com');
    }

    /**
     * @return array{0: User, 1: Organization}
     */
    private function makeUserOrg(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        return [$user, $org];
    }
}
