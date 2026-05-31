<?php

declare(strict_types=1);

namespace Tests\Unit\Support\SiteSettingsSidebarTest;

use App\Models\Server;
use App\Models\Site;
use App\Support\SiteSettingsSidebar;

function makeVmServer(): Server
{
    $server = new Server;
    $server->meta = ['host_kind' => Server::HOST_KIND_VM];

    return $server;
}
function makeContainerServer(): Server
{
    $server = new Server;
    $server->meta = ['host_kind' => Server::HOST_KIND_DOCKER];

    return $server;
}
function makeSite(Server $server, ?string $runtime = 'php'): Site
{
    $site = new Site;
    $site->setRelation('server', $server);
    $site->server_id = $server->id;
    $site->runtime = $runtime;

    return $site;
}
test('vm site has background group with cron and daemons', function () {
    $server = makeVmServer();
    $site = makeSite($server);

    $items = SiteSettingsSidebar::items($site, $server);

    $byGroup = collect($items)->groupBy('group');
    expect($byGroup->has('background'))->toBeTrue();
    $backgroundIds = $byGroup['background']->pluck('id')->all();
    expect($backgroundIds)->toContain('cron');
    expect($backgroundIds)->toContain('daemons');
});
test('background group lives between observability and access', function () {
    $server = makeVmServer();
    $site = makeSite($server);

    $items = SiteSettingsSidebar::items($site, $server);

    $groupOrder = collect($items)
        ->pluck('group')
        ->unique()
        ->values()
        ->all();

    $observabilityIdx = array_search('observability', $groupOrder, true);
    $backgroundIdx = array_search('background', $groupOrder, true);
    $accessIdx = array_search('access', $groupOrder, true);

    $this->assertNotFalse($observabilityIdx);
    $this->assertNotFalse($backgroundIdx);
    $this->assertNotFalse($accessIdx);
    expect($backgroundIdx)->toBeGreaterThan($observabilityIdx);
    expect($backgroundIdx)->toBeLessThan($accessIdx);
});
test('container site background group has engine level schedule and workers', function () {
    // Container / serverless workspaces get a tight BACKGROUND group with
    // engine-level Schedule + Workers (NOT the VM-host cron / daemons /
    // queue-workers / backups items). v1 surfaces a single tick toggle
    // behind each; future iterations expand into lists of named rules.
    $server = makeContainerServer();
    $site = makeSite($server, 'docker');

    $items = collect(SiteSettingsSidebar::items($site, $server));

    $background = $items->where('group', 'background');
    expect($background)->toHaveCount(2, 'BACKGROUND should have exactly Schedule + Workers');

    $ids = $background->pluck('id')->all();
    expect($ids)->toContain('schedule');
    expect($ids)->toContain('workers');

    // The VM-host items must NOT leak into a container/serverless sidebar.
    foreach (['cron', 'daemons', 'queue-workers', 'backups'] as $vmOnly) {
        expect($items->pluck('id')->all())->not->toContain($vmOnly);
    }
});
test('container background items point to dedicated site routes', function () {
    $server = makeContainerServer();
    $site = makeSite($server, 'docker');

    $items = collect(SiteSettingsSidebar::items($site, $server))->keyBy('id');

    expect($items['schedule']['route'] ?? null)->toBe('sites.schedule');
    expect($items['workers']['route'] ?? null)->toBe('sites.workers');
});
test('every item has a group key', function () {
    $server = makeVmServer();
    $site = makeSite($server);

    $items = SiteSettingsSidebar::items($site, $server);

    foreach ($items as $item) {
        expect($item)->toHaveKey('group');
        expect($item['group'])->not->toBeEmpty();
    }
});
test('cron and daemons link to dedicated routes', function () {
    $server = makeVmServer();
    $site = makeSite($server);

    $items = collect(SiteSettingsSidebar::items($site, $server))->keyBy('id');

    expect($items['cron']['route'] ?? null)->toBe('sites.cron');
    expect($items['daemons']['route'] ?? null)->toBe('sites.daemons');
    expect($items)->not->toHaveKey('queue-workers');
});
test('background includes schedule on server route and backups on site route', function () {
    $server = makeVmServer();
    $site = makeSite($server);

    $items = collect(SiteSettingsSidebar::items($site, $server))->keyBy('id');

    expect($items['schedule']['route'] ?? null)->toBe('servers.schedule');
    expect($items['schedule']['route_params'] ?? null)->toBe('server_with_site');
    expect($items['backups']['route'] ?? null)->toBe('sites.backups');
});
test('rails stack item is hidden when rails not detected', function () {
    $server = makeVmServer();
    $site = makeSite($server);

    $ids = collect(SiteSettingsSidebar::items($site, $server))->pluck('id')->all();
    expect($ids)->not->toContain('rails-stack');
});
test('rails stack item appears when rails detected', function () {
    $server = makeVmServer();
    $site = makeSite($server);

    // Inject the runtime detection blob the helper reads from. resolvedRuntimeAppDetection()
    // looks at the site's `meta` for a cached detection — simplest path is to override the
    // method via an anonymous-class subclass that returns a Rails framework signal.
    $site = new class extends Site
    {
        public function isRailsFrameworkDetected(): bool
        {
            return true;
        }
    };
    $site->setRelation('server', $server);
    $site->server_id = $server->id;
    $site->runtime = 'ruby';

    $ids = collect(SiteSettingsSidebar::items($site, $server))->pluck('id')->all();
    expect($ids)->toContain('rails-stack');
});
test('serverless function drops host only sections', function () {
    $server = new Server;
    $server->meta = ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS];

    $site = new Site;
    $site->setRelation('server', $server);
    $site->server_id = $server->id;
    $site->meta = ['runtime_profile' => 'digitalocean_functions_web'];

    $ids = collect(SiteSettingsSidebar::items($site, $server))->pluck('id')->all();

    // VM `dns` / `system-user` / `basic-auth` stay excluded for serverless —
    // those edit nginx and a unix user that don't exist here. `routing`,
    // however, was reintroduced as the edge-proxy management surface
    // (custom domains, redirects, headers + CORS).
    foreach (['dns', 'system-user', 'basic-auth'] as $excluded) {
        expect($ids)->not->toContain($excluded);
    }
    expect($ids)->toContain('environment');
    expect($ids)->toContain('deploy');
    expect($ids)->toContain('repository');
    expect($ids)->toContain('routing');
});
test('docker workspace drops host only sections', function () {
    // Container apps (docker, kubernetes, serverless) run behind the dply
    // edge — DNS, the host webserver, system user, basic auth, and
    // framework-specific stack tabs are all either the edge's job or
    // the operator's artifact's job, not this workspace's. `routing`
    // is now exposed (it manages the edge proxy, distinct from the VM
    // nginx-server-block routing this sidebar historically excluded).
    $server = makeContainerServer();
    $site = makeSite($server, 'docker');

    $ids = collect(SiteSettingsSidebar::items($site, $server))->pluck('id')->all();

    foreach (['dns', 'certificates', 'system-user', 'basic-auth', 'laravel-stack', 'rails-stack', 'wordpress', 'webserver-config', 'caching'] as $excluded) {
        expect($ids)->not->toContain($excluded);
    }
    expect($ids)->toContain('environment');
    expect($ids)->toContain('deploy');
    expect($ids)->toContain('repository');
    expect($ids)->toContain('runtime');
});
test('vm php site has single runtime sidebar item not language child', function () {
    $server = makeVmServer();
    $site = makeSite($server, 'php');

    $ids = collect(SiteSettingsSidebar::items($site, $server))->pluck('id')->all();

    expect($ids)->toContain('runtime');
    expect($ids)->not->toContain('runtime-php');
});
test('vm site keeps dns under routing instead of a separate sidebar item', function () {
    $server = makeVmServer();
    $site = makeSite($server);

    $ids = collect(SiteSettingsSidebar::items($site, $server))->pluck('id')->all();

    expect($ids)->toContain('routing');
    expect($ids)->not->toContain('dns');
});
test('runtime tabs include overview and language for vm php site', function () {
    $server = makeVmServer();
    $site = makeSite($server, 'php');

    expect(SiteSettingsSidebar::runtimeTabsFor($site))->toBe([
        'overview' => 'Overview',
        'php' => 'PHP',
    ]);
});
test('node site has services in background sidebar', function () {
    $server = makeVmServer();
    $site = makeSite($server, 'node');

    $ids = collect(SiteSettingsSidebar::items($site, $server))->pluck('id')->all();

    expect($ids)->toContain('services');
});
test('php site hides services from sidebar', function () {
    $server = makeVmServer();
    $site = makeSite($server, 'php');

    $ids = collect(SiteSettingsSidebar::items($site, $server))->pluck('id')->all();

    expect($ids)->not->toContain('services');
});
test('container workspace group order', function () {
    $server = makeContainerServer();
    $site = makeSite($server, 'docker');

    $groupOrder = collect(SiteSettingsSidebar::items($site, $server))
        ->pluck('group')
        ->unique()
        ->values()
        ->all();

    expect($groupOrder)->toBe(['general', 'networking', 'deploy', 'runtime', 'background', 'observability', 'danger']);
});
