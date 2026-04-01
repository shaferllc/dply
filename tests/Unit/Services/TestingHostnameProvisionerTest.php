<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Sites\TestingHostnameProvisioner;
use Illuminate\Support\Collection;
use Tests\TestCase;

class TestingHostnameProvisionerTest extends TestCase
{
    public function test_it_chooses_a_domain_from_the_owned_pool_deterministically(): void
    {
        config([
            'services.digitalocean.testing_domains' => ['dply.cc', 'dply.host', 'dply.io'],
            'services.digitalocean.testing_domain_strategy' => 'deterministic',
        ]);

        $site = new Site([
            'name' => 'Marketing API',
            'slug' => 'marketing-api',
        ]);
        $site->id = '01jtestsite0000000000000000';

        $provisioner = new TestingHostnameProvisioner;
        $first = $provisioner->chooseZone($site);
        $second = $provisioner->chooseZone($site);

        $this->assertContains($first, ['dply.cc', 'dply.host', 'dply.io']);
        $this->assertSame($first, $second);
    }

    public function test_it_builds_a_testing_hostname_from_site_slug_and_zone(): void
    {
        $site = new Site([
            'name' => 'Marketing API',
            'slug' => 'marketing-api',
        ]);
        $site->id = '01jtestsite0000000000000000';

        $hostname = (new TestingHostnameProvisioner)->buildHostname($site, 'dply.cc');

        $this->assertStringEndsWith('.dply.cc', $hostname);
        $this->assertStringContainsString('marketing-api', $hostname);
    }

    public function test_ssl_hostnames_prefer_the_testing_hostname_when_present(): void
    {
        $site = new Site([
            'meta' => [
                'testing_hostname' => [
                    'status' => 'ready',
                    'hostname' => 'preview-app.dply.cc',
                ],
            ],
        ]);

        $site->setRelation('domains', new Collection([
            new SiteDomain(['hostname' => 'app.example.com', 'is_primary' => true]),
            new SiteDomain(['hostname' => 'preview-app.dply.cc', 'is_primary' => false]),
        ]));

        $this->assertSame(['preview-app.dply.cc'], $site->sslDomainHostnames()->all());
    }
}
