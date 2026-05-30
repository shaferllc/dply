<?php

declare(strict_types=1);

namespace Tests\Unit\Services\VmDockerSiteConfigBuilderTest;

use App\Enums\SiteType;
use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Sites\CaddySiteConfigBuilder;
use App\Services\Sites\NginxSiteConfigBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function vmDockerSite(int $port = 30042): Site
{
    $site = Site::factory()->create([
        'slug' => 'docker-app',
        'type' => SiteType::Node,
        'internal_port' => $port,
        'meta' => [
            'runtime_profile' => 'docker_web',
            'runtime_target' => [
                'family' => 'byo_vm_docker',
                'platform' => 'byo',
                'mode' => 'docker',
                'vm_docker' => true,
                'publication' => ['port' => $port],
            ],
        ],
    ]);

    SiteDomain::query()->create([
        'site_id' => $site->id,
        'hostname' => 'docker-app.example.test',
        'is_primary' => true,
        'www_redirect' => false,
    ]);

    return $site->refresh()->load(['domains', 'redirects', 'basicAuthUsers']);
}

test('nginx vm docker config reverse proxies to the published host port', function () {
    $site = vmDockerSite(30055);

    $nginx = app(NginxSiteConfigBuilder::class)->build($site);

    expect($nginx)->toContain('(vm docker)')
        ->and($nginx)->toContain('proxy_pass http://127.0.0.1:30055;');
});

test('caddy vm docker config reverse proxies to the published host port', function () {
    $site = vmDockerSite(30061);

    $caddy = app(CaddySiteConfigBuilder::class)->build($site);

    expect($caddy)->toContain('reverse_proxy 127.0.0.1:30061');
});
