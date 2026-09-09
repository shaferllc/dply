<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SiteCustomerFacingHostnamesTest;

use App\Models\Site;
use App\Models\SiteDomain;
use App\Models\SiteDomainAlias;
use Illuminate\Database\Eloquent\Collection;

function siteWithAlias(): Site
{
    $site = new Site;
    $site->forceFill(['id' => '01hzzzzzzzzzzzzzzzzzzzzzzz']);

    $domain = new SiteDomain;
    $domain->forceFill(['hostname' => 'wisp.dply.io', 'is_primary' => true]);

    $alias = new SiteDomainAlias;
    $alias->forceFill(['hostname' => 'WWW.wisp.dply.io']);

    $site->setRelation('domains', new Collection([$domain]));
    $site->setRelation('domainAliases', new Collection([$alias]));

    return $site;
}

test('the customer-facing set includes www, which is stored as an alias', function () {
    // The regression: DNS iterated domains only, so `www` got an nginx
    // server_name and a cert SAN but never an A record.
    expect(siteWithAlias()->customerDomainHostnames())->toBe(['wisp.dply.io'])
        ->and(siteWithAlias()->customerFacingHostnames())->toBe(['wisp.dply.io', 'www.wisp.dply.io']);
});

test('DNS, SSL and the vhost all agree on which hostnames belong to the customer', function () {
    $site = siteWithAlias();

    expect($site->sslIssuanceHostnames())->toBe($site->customerFacingHostnames());

    foreach ($site->customerFacingHostnames() as $hostname) {
        expect($site->webserverHostnames())->toContain($hostname);
    }
});
