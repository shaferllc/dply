<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDeployStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteDeployStepPhaseBadgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_phase_badges_on_each_deploy_step(): void
    {
        [$user, $server] = $this->makeUserServer();
        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $server->organization_id,
            'runtime' => 'php',
            'runtime_version' => '8.4',
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);

        SiteDeployStep::create([
            'site_id' => $site->id,
            'sort_order' => 10,
            'step_type' => SiteDeployStep::TYPE_COMPOSER_INSTALL,
            'phase' => SiteDeployStep::PHASE_BUILD,
            'timeout_seconds' => 600,
        ]);
        SiteDeployStep::create([
            'site_id' => $site->id,
            'sort_order' => 20,
            'step_type' => SiteDeployStep::TYPE_ARTISAN_MIGRATE,
            'phase' => SiteDeployStep::PHASE_RELEASE,
            'timeout_seconds' => 300,
        ]);

        $response = $this->actingAs($user)->get(route('sites.show', [
            'server' => $server,
            'site' => $site,
            'section' => 'deploy',
        ]));

        $response->assertOk();
        // Both phase badges render.
        $response->assertSee('build');
        $response->assertSee('release');
        // Step types still render alongside the badges.
        $response->assertSee(SiteDeployStep::TYPE_COMPOSER_INSTALL);
        $response->assertSee(SiteDeployStep::TYPE_ARTISAN_MIGRATE);
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
