<?php

namespace Tests\Unit\Services\SiteWebserverConfigEditorServiceHydrateNginxTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteWebserverConfigProfile;
use App\Models\User;
use App\Services\Sites\SiteNginxProvisioner;
use App\Services\Sites\WebserverConfig\SiteWebserverConfigEditorService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('layered profile pulls before and after even when main vhost has no include paths', function () {
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
        // Single consolidated read replaces the old read-main / ensure / read-before / read-after calls.
        $mock->shouldReceive('readEditorStateFromServer')->once()->andReturn([
            'main' => $flatMain,
            'before' => '# pulled-before',
            'after' => '# pulled-after',
        ]);
    });

    $editor = app(SiteWebserverConfigEditorService::class);
    $result = $editor->hydrateEditorFromServer($site->fresh(['server']), $profile->fresh());

    expect($result['ok'])->toBeTrue();
    $profile->refresh();
    expect($profile->mode)->toBe(SiteWebserverConfigProfile::MODE_LAYERED);
    expect($profile->before_body)->toBe('# pulled-before');
    expect($profile->after_body)->toBe('# pulled-after');
    expect($profile->main_snippet_body)->toBe("location / { return 200 'from-db'; }");
    expect($profile->full_override_body)->toBeNull();
});

test('remote layered vhost flips full profile to layered before ensure', function () {
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
        $mock->shouldReceive('readEditorStateFromServer')->once()->andReturn([
            'main' => $layeredMain,
            'before' => '# x',
            'after' => '# y',
        ]);
        $mock->shouldReceive('parseLayeredMainSnippetFromVhost')->once()->andReturn('location / { }');
    });

    $editor = app(SiteWebserverConfigEditorService::class);
    $result = $editor->hydrateEditorFromServer($site->fresh(['server']), $profile->fresh());

    expect($result['ok'])->toBeTrue();
    $profile->refresh();
    expect($profile->mode)->toBe(SiteWebserverConfigProfile::MODE_LAYERED);
    expect($profile->main_snippet_body)->toBe('location / { }');
});
