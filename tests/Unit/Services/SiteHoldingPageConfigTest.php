<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SiteHoldingPageConfigTest;

use App\Enums\SiteType;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function nodeSite(bool $withApp): Site
{
    $server = Server::factory()->ready()->create([
        'meta' => ['webserver' => 'nginx', 'runtime_defaults' => ['node' => '22']],
    ]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'type' => SiteType::Node,
        'runtime' => 'node',
        'internal_port' => 3000,
        'start_command' => 'npm run start',
        'git_repository_url' => $withApp ? 'https://github.com/acme/app.git' : null,
        'last_deploy_at' => $withApp ? now() : null,
    ]);
}

test('a node site with no application is configured as static, not proxied', function () {
    $site = nodeSite(withApp: false)->fresh();

    // Nothing is listening on 3000 — no code, no unit, no process — so a proxy
    // vhost could only ever 502.
    expect($site->lacksInstalledApp())->toBeTrue()
        ->and($site->type)->toBe(SiteType::Node)
        ->and($site->configSiteType())->toBe(SiteType::Static);
});

test('a deployed node site is still configured as node', function () {
    $site = nodeSite(withApp: true)->fresh();

    // Once an app exists, a dead port must 502 rather than silently showing a
    // splash page — that would hide a real outage.
    expect($site->lacksInstalledApp())->toBeFalse()
        ->and($site->configSiteType())->toBe(SiteType::Node);
});

test('php and static sites are unaffected', function () {
    $server = Server::factory()->ready()->create(['meta' => ['webserver' => 'nginx']]);

    $php = Site::factory()->create(['server_id' => $server->id, 'type' => SiteType::Php, 'runtime' => 'php']);
    $static = Site::factory()->create(['server_id' => $server->id, 'type' => SiteType::Static, 'runtime' => 'static']);

    expect($php->fresh()->configSiteType())->toBe(SiteType::Php)
        ->and($static->fresh()->configSiteType())->toBe(SiteType::Static);
});

test('every config builder matches on configSiteType, not type', function () {
    // The holding state must not be implemented in one web server and forgotten
    // in the other three.
    foreach ([
        'app/Services/Sites/NginxSiteConfigBuilder.php',
        'app/Services/Sites/CaddySiteConfigBuilder.php',
        'app/Services/Sites/ApacheSiteConfigBuilder.php',
        'app/Services/Sites/OpenLiteSpeedSiteConfigBuilder.php',
    ] as $file) {
        $source = file_get_contents(base_path($file));

        expect($source)->toContain('match ($site->configSiteType())')
            ->and($source)->not->toContain('match ($site->type)');
    }
});
