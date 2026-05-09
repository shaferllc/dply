<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FleetEnvSearchPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_landing_state_has_no_results_until_query(): void
    {
        [$user] = $this->makeUserOrg();

        $response = $this->actingAs($user)->get(route('fleet.env-search'));

        $response->assertOk()
            ->assertSee('Fleet env search')
            ->assertSee('Enter a key', false);
    }

    public function test_finds_exact_key_across_sites(): void
    {
        [$user, $org] = $this->makeUserOrg();
        $server = Server::factory()->create(['organization_id' => $org->id]);
        Site::factory()->create([
            'server_id' => $server->id,
            'organization_id' => $org->id,
            'name' => 'alpha',
            'env_file_content' => "DATABASE_URL=postgres://a\nOTHER=noise",
        ]);
        Site::factory()->create([
            'server_id' => $server->id,
            'organization_id' => $org->id,
            'name' => 'bravo',
            'env_file_content' => 'DATABASE_URL=postgres://b',
        ]);

        $response = $this->actingAs($user)->get(route('fleet.env-search').'?q=DATABASE_URL');

        $response->assertOk()
            ->assertSee('alpha')
            ->assertSee('bravo')
            ->assertSee('DATABASE_URL')
            ->assertDontSee('OTHER');
    }

    public function test_prefix_mode(): void
    {
        [$user, $org] = $this->makeUserOrg();
        $server = Server::factory()->create(['organization_id' => $org->id]);
        Site::factory()->create([
            'server_id' => $server->id,
            'organization_id' => $org->id,
            'env_file_content' => "AWS_REGION=us-east-1\nAWS_BUCKET=data\nOTHER=x",
        ]);

        $response = $this->actingAs($user)->get(route('fleet.env-search').'?q=AWS_&mode=prefix');

        $response->assertOk()
            ->assertSee('AWS_REGION')
            ->assertSee('AWS_BUCKET')
            ->assertDontSee('OTHER');
    }

    public function test_values_masked_by_default(): void
    {
        [$user, $org] = $this->makeUserOrg();
        $server = Server::factory()->create(['organization_id' => $org->id]);
        Site::factory()->create([
            'server_id' => $server->id,
            'organization_id' => $org->id,
            'env_file_content' => 'API_KEY=super-secret-token',
        ]);

        $response = $this->actingAs($user)->get(route('fleet.env-search').'?q=API_KEY');

        $response->assertOk()
            ->assertDontSee('super-secret-token');
    }

    public function test_no_match_message(): void
    {
        [$user, $org] = $this->makeUserOrg();
        $server = Server::factory()->create(['organization_id' => $org->id]);
        Site::factory()->create(['server_id' => $server->id, 'organization_id' => $org->id]);

        $response = $this->actingAs($user)->get(route('fleet.env-search').'?q=NOPE');

        $response->assertOk()
            ->assertSee('No matches across the fleet');
    }

    public function test_only_searches_within_current_org(): void
    {
        [$user, $org] = $this->makeUserOrg();
        $otherOrg = Organization::factory()->create();
        $otherServer = Server::factory()->create(['organization_id' => $otherOrg->id]);
        Site::factory()->create([
            'server_id' => $otherServer->id,
            'organization_id' => $otherOrg->id,
            'name' => 'sneaky',
            'env_file_content' => 'CROSS_ORG_KEY=leak',
        ]);

        $response = $this->actingAs($user)->get(route('fleet.env-search').'?q=CROSS_ORG_KEY');

        $response->assertOk()
            ->assertDontSee('sneaky')
            ->assertSee('No matches');
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
