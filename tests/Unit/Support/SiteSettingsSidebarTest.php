<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Models\Server;
use App\Models\Site;
use App\Support\SiteSettingsSidebar;
use Tests\TestCase;

class SiteSettingsSidebarTest extends TestCase
{
    private function makeVmServer(): Server
    {
        $server = new Server;
        $server->meta = ['host_kind' => Server::HOST_KIND_VM];

        return $server;
    }

    private function makeContainerServer(): Server
    {
        $server = new Server;
        $server->meta = ['host_kind' => Server::HOST_KIND_DOCKER];

        return $server;
    }

    private function makeSite(Server $server, ?string $runtime = 'php'): Site
    {
        $site = new Site;
        $site->setRelation('server', $server);
        $site->server_id = $server->id;
        $site->runtime = $runtime;

        return $site;
    }

    public function test_vm_site_has_background_group_with_cron_and_daemons(): void
    {
        $server = $this->makeVmServer();
        $site = $this->makeSite($server);

        $items = SiteSettingsSidebar::items($site, $server);

        $byGroup = collect($items)->groupBy('group');
        $this->assertTrue($byGroup->has('background'));
        $backgroundIds = $byGroup['background']->pluck('id')->all();
        $this->assertContains('cron', $backgroundIds);
        $this->assertContains('daemons', $backgroundIds);
    }

    public function test_background_group_lives_between_observability_and_access(): void
    {
        $server = $this->makeVmServer();
        $site = $this->makeSite($server);

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
        $this->assertGreaterThan($observabilityIdx, $backgroundIdx);
        $this->assertLessThan($accessIdx, $backgroundIdx);
    }

    public function test_container_site_has_no_background_group(): void
    {
        $server = $this->makeContainerServer();
        $site = $this->makeSite($server, 'docker');

        $items = SiteSettingsSidebar::items($site, $server);

        $groups = collect($items)->pluck('group')->unique()->all();
        $this->assertNotContains('background', $groups);
    }

    public function test_every_item_has_a_group_key(): void
    {
        $server = $this->makeVmServer();
        $site = $this->makeSite($server);

        $items = SiteSettingsSidebar::items($site, $server);

        foreach ($items as $item) {
            $this->assertArrayHasKey('group', $item, "Item {$item['id']} is missing a group key");
            $this->assertNotEmpty($item['group']);
        }
    }

    public function test_cron_and_daemons_link_to_dedicated_routes(): void
    {
        $server = $this->makeVmServer();
        $site = $this->makeSite($server);

        $items = collect(SiteSettingsSidebar::items($site, $server))->keyBy('id');

        $this->assertSame('sites.cron', $items['cron']['route'] ?? null);
        $this->assertSame('sites.daemons', $items['daemons']['route'] ?? null);
        $this->assertSame('sites.queue-workers', $items['queue-workers']['route'] ?? null);
    }

    public function test_background_includes_schedule_and_backups_pointing_to_server_routes(): void
    {
        $server = $this->makeVmServer();
        $site = $this->makeSite($server);

        $items = collect(SiteSettingsSidebar::items($site, $server))->keyBy('id');

        $this->assertSame('servers.schedule', $items['schedule']['route'] ?? null);
        $this->assertSame('server_only', $items['schedule']['route_params'] ?? null);
        $this->assertSame('servers.backups', $items['backups']['route'] ?? null);
        $this->assertSame('server_only', $items['backups']['route_params'] ?? null);
    }

    public function test_rails_stack_item_is_hidden_when_rails_not_detected(): void
    {
        $server = $this->makeVmServer();
        $site = $this->makeSite($server);

        $ids = collect(SiteSettingsSidebar::items($site, $server))->pluck('id')->all();
        $this->assertNotContains('rails-stack', $ids);
    }

    public function test_rails_stack_item_appears_when_rails_detected(): void
    {
        $server = $this->makeVmServer();
        $site = $this->makeSite($server);
        // Inject the runtime detection blob the helper reads from. resolvedRuntimeAppDetection()
        // looks at the site's `meta` for a cached detection — simplest path is to override the
        // method via an anonymous-class subclass that returns a Rails framework signal.
        $site = new class extends \App\Models\Site
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
        $this->assertContains('rails-stack', $ids);
    }

    public function test_serverless_function_drops_host_only_sections(): void
    {
        $server = new Server;
        $server->meta = ['host_kind' => Server::HOST_KIND_DIGITALOCEAN_FUNCTIONS];

        $site = new Site;
        $site->setRelation('server', $server);
        $site->server_id = $server->id;
        $site->meta = ['runtime_profile' => 'digitalocean_functions_web'];

        $ids = collect(SiteSettingsSidebar::items($site, $server))->pluck('id')->all();

        foreach (['routing', 'dns', 'system-user', 'basic-auth'] as $excluded) {
            $this->assertNotContains($excluded, $ids, $excluded.' should not appear for a serverless function');
        }
        $this->assertContains('environment', $ids);
        $this->assertContains('deploy', $ids);
        $this->assertContains('repository', $ids);
    }

    public function test_docker_workspace_keeps_host_sections(): void
    {
        $server = $this->makeContainerServer();
        $site = $this->makeSite($server);

        $ids = collect(SiteSettingsSidebar::items($site, $server))->pluck('id')->all();

        $this->assertContains('routing', $ids);
        $this->assertContains('system-user', $ids);
    }
}
