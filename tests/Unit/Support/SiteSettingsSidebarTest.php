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
    }
}
