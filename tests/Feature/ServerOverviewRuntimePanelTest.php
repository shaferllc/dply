<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerOverviewRuntimePanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_shows_polyglot_runtime_inventory_panel(): void
    {
        $user = $this->seedUser();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'setup_status' => Server::SETUP_STATUS_DONE,
            'meta' => [
                'webserver' => 'nginx',
                'php_version' => '8.4',
                'runtime_defaults' => [
                    'node' => '22',
                    'python' => '3.12',
                    'ruby' => '3.3',
                    'go' => '1.22',
                ],
            ],
        ]);

        $response = $this->actingAs($user)->get(route('servers.overview', $server));

        $response->assertOk()
            ->assertSee('Installed runtimes')
            ->assertSee('PHP')
            ->assertSee('8.4')
            ->assertSee('Node')
            ->assertSee('22')
            ->assertSee('Python')
            ->assertSee('3.12')
            ->assertSee('Ruby')
            ->assertSee('3.3')
            ->assertSee('Go')
            ->assertSee('1.22');
    }

    public function test_overview_renders_empty_state_when_no_runtimes_installed(): void
    {
        $user = $this->seedUser();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'setup_status' => Server::SETUP_STATUS_DONE,
            'meta' => [
                'webserver' => 'nginx',
                // No php_version, no runtime_defaults — pure DB / cache /
                // load-balancer node still renders the panel as an empty
                // state with the install affordance (PP).
            ],
        ]);

        $response = $this->actingAs($user)->get(route('servers.overview', $server));

        $response->assertOk()
            ->assertSee('Installed runtimes')
            ->assertSee('No runtimes are installed')
            ->assertSee('dply:install-runtime');
    }

    public function test_overview_renders_php_only_for_legacy_servers(): void
    {
        $user = $this->seedUser();
        $org = $user->currentOrganization();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'setup_status' => Server::SETUP_STATUS_DONE,
            'meta' => [
                'webserver' => 'nginx',
                'php_version' => '8.3',
                // No runtime_defaults — server provisioned before mise
                // integration landed.
            ],
        ]);

        $response = $this->actingAs($user)->get(route('servers.overview', $server));

        $response->assertOk()
            ->assertSee('Installed runtimes')
            ->assertSee('PHP')
            ->assertSee('8.3')
            ->assertDontSee('Managed by mise');
    }

    private function seedUser(): User
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        return $user;
    }
}
