<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteWebserverConfigProfile;
use App\Models\User;
use App\Services\Sites\NginxSiteConfigBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteWebserverConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_webserver_config_page_loads(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);
        session(['current_organization_id' => $org->id]);

        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['webserver' => 'nginx'],
        ]);

        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Site::STATUS_NGINX_ACTIVE,
        ]);

        SiteDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => 'app.example.test',
            'is_primary' => true,
        ]);

        $response = $this->actingAs($user)->get(route('sites.webserver-config', [$server, $site]));

        $response->assertOk();
        $response->assertSee('Web server config', false);
    }

    public function test_nginx_layered_profile_includes_dply_paths_in_generated_config(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'meta' => ['webserver' => 'nginx'],
        ]);

        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Site::STATUS_NGINX_ACTIVE,
            'nginx_extra_raw' => '',
        ]);

        SiteDomain::query()->create([
            'site_id' => $site->id,
            'hostname' => 'app.example.test',
            'is_primary' => true,
        ]);

        $profile = SiteWebserverConfigProfile::query()->create([
            'site_id' => $site->id,
            'webserver' => 'nginx',
            'mode' => SiteWebserverConfigProfile::MODE_LAYERED,
            'before_body' => '# before',
            'main_snippet_body' => 'location = /health { return 200; }',
            'after_body' => '# after',
        ]);

        $builder = app(NginxSiteConfigBuilder::class);
        $config = $builder->build($site->fresh(), $profile);

        $basename = $site->fresh()->nginxConfigBasename();
        $this->assertStringContainsString('/etc/nginx/dply/'.$basename.'/before/*.conf', $config);
        $this->assertStringContainsString('/etc/nginx/dply/'.$basename.'/after/*.conf', $config);
        $this->assertStringContainsString('location = /health', $config);
    }
}
