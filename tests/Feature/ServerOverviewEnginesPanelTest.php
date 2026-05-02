<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerDatabaseEngine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerOverviewEnginesPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_shows_engines_panel_with_default_marker(): void
    {
        [$user, $server] = $this->makeUserAndServer();
        ServerDatabaseEngine::create([
            'server_id' => $server->id,
            'engine' => 'postgres',
            'version' => '17',
            'is_default' => true,
        ]);
        ServerDatabaseEngine::create([
            'server_id' => $server->id,
            'engine' => 'mysql84',
            'version' => '8.4',
            'is_default' => false,
        ]);

        $response = $this->actingAs($user)->get(route('servers.overview', $server));

        $response->assertOk()
            ->assertSee('Database engines')
            ->assertSee('postgres')
            ->assertSee('17')
            ->assertSee('mysql84')
            ->assertSee('8.4')
            ->assertSee('default')
            ->assertSee('dply:server:add-engine');
    }

    public function test_overview_renders_engines_empty_state_with_install_hint(): void
    {
        [$user, $server] = $this->makeUserAndServer();

        $response = $this->actingAs($user)->get(route('servers.overview', $server));

        $response->assertOk()
            ->assertSee('No database engines are registered')
            ->assertSee('dply:server:add-engine');
    }

    /**
     * @return array{0: User, 1: Server}
     */
    private function makeUserAndServer(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'setup_status' => Server::SETUP_STATUS_DONE,
            'meta' => ['webserver' => 'nginx'],
        ]);

        return [$user, $server];
    }
}
