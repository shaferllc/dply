<?php

declare(strict_types=1);

namespace Tests\Unit\CustomSiteWebserverNoopTest;

use App\Models\Server;
use App\Models\Site;
use App\Services\Deploy\DockerRuntimeDockerfileBuilder;
use App\Services\Sites\ApacheSiteConfigBuilder;
use App\Services\Sites\CaddySiteConfigBuilder;
use App\Services\Sites\NginxSiteConfigBuilder;
use App\Services\Sites\OpenLiteSpeedSiteConfigBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('nginx builder returns empty for custom', function () {
    $site = customSite();
    $config = app(NginxSiteConfigBuilder::class)->build($site);
    expect($config)->toBe('');
});
test('apache builder returns empty for custom', function () {
    $site = customSite();
    $config = app(ApacheSiteConfigBuilder::class)->build($site);
    expect($config)->toBe('');
});
test('caddy builder returns empty for custom', function () {
    $site = customSite();
    $config = app(CaddySiteConfigBuilder::class)->build($site);
    expect($config)->toBe('');
});
test('openlitespeed builder returns empty for custom', function () {
    $site = customSite();
    $config = app(OpenLiteSpeedSiteConfigBuilder::class)->build($site);
    expect($config)->toBe('');
});
test('dockerfile builder returns empty for custom', function () {
    $site = customSite();
    $dockerfile = app(DockerRuntimeDockerfileBuilder::class)->build($site);
    expect($dockerfile)->toBe('');
});
function customSite(): Site
{
    $server = Server::factory()->ready()->create([
        'meta' => ['host_kind' => Server::HOST_KIND_VM, 'webserver' => 'nginx'],
    ]);

    return Site::factory()->custom()->create(['server_id' => $server->id]);
}
