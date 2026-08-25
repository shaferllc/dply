<?php

declare(strict_types=1);

namespace Tests\Unit\Services\TestingHostnameProvisionerTest;

use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SitePreviewDomain;
use App\Services\Sites\TestingHostnameProvisioner;
use Illuminate\Support\Collection;

test('vm testing hostnames prefer cloudflare when a platform token exists', function () {
    config([
        'services.cloudflare.provider' => 'cloudflare',
        'services.cloudflare.vm_apex' => 'on-dply.cc',
        'services.cloudflare.vm' => ['on-dply.cc', 'dply.host'],
        'services.cloudflare.key' => 'cf-dns-token',
        'services.namecheap.api_user' => 'tshafer',
        'services.namecheap.api_key' => 'nc-key',
        'services.namecheap.client_ip' => '1.2.3.4',
        'services.digitalocean.token' => 'do-token',
    ]);

    $site = new Site([
        'name' => 'Marketing API',
        'slug' => 'marketing-api',
    ]);

    $routing = app(TestingHostnameProvisioner::class)->testingDnsRoutingForSite($site);

    expect($routing['provider'])->toBe('cloudflare');
    expect($routing['token'])->toBe('cf-dns-token');
    expect($routing['credential'])->toBeNull();
});

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
test('testing hostnames are cloudflare-only — no digitalocean or namecheap fallback', function () {
    // The old behaviour routed to DigitalOcean (or Namecheap) when no Cloudflare
    // token was set. That could never work: the testing zones are on Cloudflare,
    // so any other provider was guaranteed to fail at the DNS write, just later
    // and with a more confusing message.
    config([
        'services.cloudflare.vm' => ['dply.host'],
        'services.cloudflare.key' => '',
        'services.namecheap.api_user' => 'nc',
        'services.namecheap.api_key' => 'nc_key',
        'services.namecheap.client_ip' => '1.2.3.4',
        'services.digitalocean.token' => 'do_token',
        'services.digitalocean.testing_domains' => ['dply.host'],
    ]);

    $site = new Site(['name' => 'Marketing API', 'slug' => 'marketing-api']);

    expect(fn () => app(TestingHostnameProvisioner::class)->testingDnsRoutingForSite($site))
        ->toThrow(\RuntimeException::class, 'CLOUDFLARE_DNS_API_TOKEN');
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

/**
 * Testing hostnames live on dply-OWNED zones, so only a platform credential
 * can ever serve them. A customer's Cloudflare token structurally cannot see
 * on-dply.cc; falling back to one turned "dply's token is misconfigured" into
 * "Zone [on-dply.cc] was not found in this Cloudflare account", blaming the
 * customer's account for a platform problem.
 */
test('a customer cloudflare credential is never used for a dply testing zone', function () {
    $provisioner = new \ReflectionClass(\App\Services\Sites\TestingHostnameProvisioner::class);
    $source = file_get_contents((string) $provisioner->getFileName());

    // The routing method must not reach for an org-scoped credential.
    $routing = substr(
        $source,
        (int) strpos($source, 'private function resolveTestingProviderForSite'),
    );
    $routing = substr($routing, 0, (int) strpos($routing, "\n    /**"));

    expect($routing)->not->toContain("->where('provider', 'cloudflare')")
        ->and($routing)->not->toContain("->where('provider', 'digitalocean')")
        ->and($routing)->not->toContain('SiteDnsProviderFactory::forCredential');
});

test('the platform failure names the platform, not the customer account', function () {
    config([
        'services.cloudflare.key' => '',
        'services.namecheap.api_key' => '',
        'services.digitalocean.token' => '',
    ]);

    $site = new \App\Models\Site(['type' => \App\Enums\SiteType::Php]);

    $method = new \ReflectionMethod(\App\Services\Sites\TestingHostnameProvisioner::class, 'resolveTestingProviderForSite');
    $method->setAccessible(true);

    expect(fn () => $method->invoke(app(\App\Services\Sites\TestingHostnameProvisioner::class), $site))
        ->toThrow(\RuntimeException::class, 'CLOUDFLARE_DNS_API_TOKEN');
});
