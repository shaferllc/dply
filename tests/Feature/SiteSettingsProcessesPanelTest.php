<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteProcess;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteSettingsProcessesPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_processes_panel_lists_web_and_worker_for_node_site(): void
    {
        [$user, $server] = $this->makeUserServer();
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $server->organization_id,
            'runtime' => 'node',
            'start_command' => 'npm start',
            'internal_port' => 30001,
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);
        // Update auto-created web row's command (Site::created hook
        // creates the row with command=null).
        $site->processes()->where('type', SiteProcess::TYPE_WEB)
            ->update(['command' => 'npm start']);
        $site->processes()->create([
            'type' => SiteProcess::TYPE_WORKER,
            'name' => 'worker',
            'command' => 'npm run worker',
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', [
            'server' => $server,
            'site' => $site,
        ]));

        $response->assertOk()
            ->assertSee('Site processes')
            ->assertSee('npm start')
            ->assertSee('npm run worker')
            ->assertSee('worker');
    }

    public function test_processes_panel_omitted_for_static_site(): void
    {
        [$user, $server] = $this->makeUserServer();
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $server->organization_id,
            'runtime' => 'static',
            'type' => \App\Enums\SiteType::Static,
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', [
            'server' => $server,
            'site' => $site,
        ]));

        // Static sites have no SiteProcess rows (Site::created hook
        // skips static type), so the panel doesn't render.
        $response->assertOk()
            ->assertDontSee('Site processes');
    }

    public function test_processes_panel_marks_inactive_processes(): void
    {
        [$user, $server] = $this->makeUserServer();
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $server->organization_id,
            'runtime' => 'node',
            'start_command' => 'npm start',
            'internal_port' => 30001,
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);
        $site->processes()->where('type', SiteProcess::TYPE_WEB)
            ->update(['command' => 'npm start']);
        $site->processes()->create([
            'type' => SiteProcess::TYPE_WORKER,
            'name' => 'inactive-worker',
            'command' => 'node old-worker.js',
            'is_active' => false,
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', [
            'server' => $server,
            'site' => $site,
        ]));

        $response->assertOk()
            ->assertSee('inactive-worker')
            ->assertSee('inactive');
    }

    /**
     * @return array{0: User, 1: Server}
     */
    private function makeUserServer(): array
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => [
                'webserver' => 'nginx',
                'php_inventory' => [
                    'supported' => true,
                    'installed_versions' => ['8.4'],
                ],
            ],
        ]);

        return [$user, $server];
    }
}
