<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteSettingsRecentDeploymentsPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_panel_shows_recent_deployments_with_phase_breakdown(): void
    {
        [$user, $server, $site] = $this->makeUserSite();
        $deployment = SiteDeployment::query()->create([
            'site_id' => $site->id,
            'project_id' => $site->project_id,
            'idempotency_key' => 'dep-1',
            'trigger' => 'webhook',
            'status' => SiteDeployment::STATUS_SUCCESS,
            'started_at' => now()->subMinutes(2),
            'finished_at' => now(),
        ]);
        $deployment->recordPhaseResults('build', [
            ['step_id' => '1', 'command' => 'composer install', 'ok' => true, 'output' => '', 'duration_ms' => 4200],
        ]);
        $deployment->recordPhaseResults('release', [
            ['step_id' => '2', 'command' => 'php artisan migrate --force', 'ok' => true, 'output' => '', 'duration_ms' => 800],
        ]);
        $deployment->recordPhaseResults('restart', [
            ['step_id' => 'restart', 'command' => 'sudo systemctl reload php8.4-fpm', 'ok' => true, 'output' => '', 'duration_ms' => 60],
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

        $response->assertOk()
            ->assertSee('Recent deployments')
            ->assertSee('webhook')
            ->assertSee('build (1)')
            ->assertSee('release (1)')
            ->assertSee('restart (1)');
    }

    public function test_panel_omits_when_no_deployments_have_phase_results(): void
    {
        [$user, $server, $site] = $this->makeUserSite();

        // Create a deployment without phase_results — predates the
        // runner integration.
        SiteDeployment::query()->create([
            'site_id' => $site->id,
            'project_id' => $site->project_id,
            'idempotency_key' => 'dep-old',
            'trigger' => 'manual',
            'status' => SiteDeployment::STATUS_SUCCESS,
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

        $response->assertOk()
            ->assertDontSee('Recent deployments');
    }

    public function test_panel_renders_failed_phase_in_rose(): void
    {
        [$user, $server, $site] = $this->makeUserSite();
        $deployment = SiteDeployment::query()->create([
            'site_id' => $site->id,
            'project_id' => $site->project_id,
            'idempotency_key' => 'dep-fail',
            'trigger' => 'manual',
            'status' => SiteDeployment::STATUS_FAILED,
            'started_at' => now()->subMinutes(1),
            'finished_at' => now(),
        ]);
        $deployment->recordPhaseResults('build', [
            ['step_id' => '1', 'command' => 'composer install', 'ok' => false, 'output' => 'oh no', 'duration_ms' => 1200],
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', ['server' => $server, 'site' => $site]));

        $response->assertOk()
            ->assertSee('failed')
            // Failed step's output is surfaced.
            ->assertSee('oh no');
    }

    /**
     * @return array{0: User, 1: Server, 2: Site}
     */
    private function makeUserSite(): array
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
                'php_inventory' => ['supported' => true, 'installed_versions' => ['8.4']],
            ],
        ]);
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'runtime' => 'php',
            'runtime_version' => '8.4',
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);
        $site->refresh();

        return [$user, $server, $site];
    }
}
