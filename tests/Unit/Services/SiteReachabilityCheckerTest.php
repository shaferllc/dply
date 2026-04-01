<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Sites\SiteReachabilityChecker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SiteReachabilityCheckerTest extends TestCase
{
    public function test_it_marks_a_site_reachable_when_localhost_responds(): void
    {
        Http::fake([
            'http://localhost' => Http::response('ok', 200),
        ]);

        $site = new Site([
            'meta' => [
                'testing_hostname' => [
                    'status' => 'ready',
                    'hostname' => 'localhost',
                ],
            ],
        ]);
        $site->setRelation('domains', new Collection([
            new SiteDomain(['hostname' => 'app.example.com', 'is_primary' => true]),
        ]));

        $result = (new SiteReachabilityChecker)->check($site);

        $this->assertTrue($result['ok']);
        $this->assertSame('localhost', $result['hostname']);
        $this->assertSame('http://localhost', $result['url']);
    }

    public function test_it_reports_an_unreachable_site_when_no_hostname_resolves(): void
    {
        Http::fake();

        $site = new Site;
        $site->setRelation('domains', new Collection([
            new SiteDomain(['hostname' => 'invalid.invalid', 'is_primary' => true]),
        ]));

        $result = (new SiteReachabilityChecker)->check($site);

        $this->assertFalse($result['ok']);
        $this->assertSame('No site hostname resolves yet.', $result['error']);
    }
}
