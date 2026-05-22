<?php

declare(strict_types=1);

namespace Tests\Unit\Services\TestingHostnameProvisionerTest;

use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SitePreviewDomain;
use App\Services\Sites\TestingHostnameProvisioner;
use Illuminate\Support\Collection;

test('it chooses a domain from the owned pool deterministically', function () {
    config([
        'services.digitalocean.testing_domains' => ['dply.cc', 'dply.host', 'dply.io'],
        'services.digitalocean.testing_domain_strategy' => 'deterministic',
    ]);

    $site = new Site([
        'name' => 'Marketing API',
        'slug' => 'marketing-api',
    ]);
    $site->id = '01jtestsite0000000000000000';

    $provisioner = app(TestingHostnameProvisioner::class);
    $first = $provisioner->chooseZone($site);
    $second = $provisioner->chooseZone($site);

    expect(['dply.cc', 'dply.host', 'dply.io'])->toContain($first);
    expect($second)->toBe($first);
});
test('it builds a testing hostname from site slug and zone', function () {
    $site = new Site([
        'name' => 'Marketing API',
        'slug' => 'marketing-api',
    ]);
    $site->id = '01jtestsite0000000000000000';

    $hostname = app(TestingHostnameProvisioner::class)->buildHostname($site, 'dply.cc');

    expect($hostname)->toEndWith('.dply.cc');
    $this->assertStringContainsString('marketing-api', $hostname);
});
test('ssl hostnames prefer the testing hostname when present', function () {
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

    expect($site->sslDomainHostnames()->all())->toBe(['preview-app.dply.cc']);
});
test('testing hostname prefers primary preview domain over legacy meta', function () {
    $site = new Site([
        'meta' => [
            'testing_hostname' => [
                'status' => 'ready',
                'hostname' => 'legacy-preview.dply.cc',
            ],
        ],
    ]);

    $site->setRelation('previewDomains', new Collection([
        new SitePreviewDomain([
            'hostname' => 'preview-app.dply.cc',
            'dns_status' => 'ready',
            'is_primary' => true,
        ]),
    ]));

    expect($site->testingHostname())->toBe('preview-app.dply.cc');
    expect($site->testingHostnameStatus())->toBe('ready');
});
