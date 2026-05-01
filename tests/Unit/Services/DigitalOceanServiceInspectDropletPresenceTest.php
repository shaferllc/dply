<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\DigitalOceanService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DigitalOceanServiceInspectDropletPresenceTest extends TestCase
{
    public function test_inspect_reports_gone_on_404(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/droplets/555' => Http::response(['message' => 'Not found'], 404),
        ]);

        $svc = new DigitalOceanService('do-token-test');
        $result = $svc->inspectDropletPresence(555);

        $this->assertSame('gone', $result['state']);
    }

    public function test_inspect_reports_present_on_200(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/droplets/777' => Http::response(['droplet' => ['id' => 777]], 200),
        ]);

        $svc = new DigitalOceanService('do-token-test');
        $result = $svc->inspectDropletPresence(777);

        $this->assertSame('present', $result['state']);
    }

    public function test_inspect_reports_unknown_on_other_errors(): void
    {
        Http::fake([
            'api.digitalocean.com/v2/droplets/888' => Http::response(['message' => 'Server error'], 500),
        ]);

        $svc = new DigitalOceanService('do-token-test');
        $result = $svc->inspectDropletPresence(888);

        $this->assertSame('unknown', $result['state']);
        $this->assertArrayHasKey('detail', $result);
    }
}
