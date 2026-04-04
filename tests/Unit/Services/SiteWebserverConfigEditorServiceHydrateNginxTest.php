<?php

namespace Tests\Unit\Services;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteWebserverConfigProfile;
use App\Models\User;
use App\Services\Sites\SiteNginxProvisioner;
use App\Services\Sites\WebserverConfig\SiteWebserverConfigEditorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SiteWebserverConfigEditorServiceHydrateNginxTest extends TestCase
{
    use RefreshDatabase;

    public function test_layered_profile_pulls_before_and_after_even_when_main_vhost_has_no_include_paths(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'ssh_private_key' => 'fake-test-key',
            'meta' => ['webserver' => 'nginx'],
        ]);

        $site = Site::factory()->create([
            'server_id' => $server->id,
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'status' => Site::STATUS_NGINX_ACTIVE,
            'nginx_extra_raw' => "location / { return 200 'ok'; }",
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
            'before_body' => '',
            'main_snippet_body' => "location / { return 200 'from-db'; }",
            'after_body' => '',
        ]);

        $flatMain = "server {\n    listen 80;\n    server_name app.example.test;\n}\n";

        $this->mock(SiteNginxProvisioner::class, function ($mock) use ($flatMain): void {
            $mock->shouldReceive('readCurrentMainConfig')->once()->andReturn($flatMain);
            $mock->shouldReceive('ensureNginxLayerSnippetFilesIfMissing')->once();
            $mock->shouldReceive('readLayerSnippetFile')->with(Mockery::type(Site::class), 'before')->once()->andReturn('# pulled-before');
            $mock->shouldReceive('readLayerSnippetFile')->with(Mockery::type(Site::class), 'after')->once()->andReturn('# pulled-after');
        });

        $editor = app(SiteWebserverConfigEditorService::class);
        $result = $editor->hydrateEditorFromServer($site->fresh(['server']), $profile->fresh());

        $this->assertTrue($result['ok']);
        $profile->refresh();
        $this->assertSame(SiteWebserverConfigProfile::MODE_LAYERED, $profile->mode);
        $this->assertSame('# pulled-before', $profile->before_body);
        $this->assertSame('# pulled-after', $profile->after_body);
        $this->assertSame("location / { return 200 'from-db'; }", $profile->main_snippet_body);
        $this->assertNull($profile->full_override_body);
    }

    public function test_remote_layered_vhost_flips_full_profile_to_layered_before_ensure(): void
    {
        $user = User::factory()->create();
        $org = Organization::factory()->create();
        $org->users()->attach($user->id, ['role' => 'owner']);

        $server = Server::factory()->ready()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
            'ssh_private_key' => 'fake-test-key',
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

        $profile = SiteWebserverConfigProfile::query()->create([
            'site_id' => $site->id,
            'webserver' => 'nginx',
            'mode' => SiteWebserverConfigProfile::MODE_FULL_OVERRIDE,
            'full_override_body' => 'old',
        ]);

        $basename = $site->fresh()->nginxConfigBasename();
        $base = rtrim(config('sites.nginx_dply_site_path'), '/').'/'.$basename;

        $layeredMain = <<<NGX
server {
    listen 80;
    include {$base}/before/*.conf;
    location / { }
    include {$base}/after/*.conf;
}
NGX;

        $this->mock(SiteNginxProvisioner::class, function ($mock) use ($layeredMain): void {
            $mock->shouldReceive('readCurrentMainConfig')->once()->andReturn($layeredMain);
            $mock->shouldReceive('ensureNginxLayerSnippetFilesIfMissing')->once();
            $mock->shouldReceive('readLayerSnippetFile')->twice()->andReturn('# x', '# y');
            $mock->shouldReceive('parseLayeredMainSnippetFromVhost')->once()->andReturn('location / { }');
        });

        $editor = app(SiteWebserverConfigEditorService::class);
        $result = $editor->hydrateEditorFromServer($site->fresh(['server']), $profile->fresh());

        $this->assertTrue($result['ok']);
        $profile->refresh();
        $this->assertSame(SiteWebserverConfigProfile::MODE_LAYERED, $profile->mode);
        $this->assertSame('location / { }', $profile->main_snippet_body);
    }
}
