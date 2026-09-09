<?php

declare(strict_types=1);

namespace Tests\Unit\Models\WildcardCoversAllHostnamesTest;

use App\Models\Server;
use App\Models\ServerWildcardCertificate;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * isCoveredByServerWildcard() meant only "a wildcard exists for my testing
 * zone" — a statement about the preview hostname alone. Provisioning used it to
 * set ssl_status = active for the whole site, so the sites list showed an SSL
 * badge beside placehold.cloud while the only certificate was *.on-dply.cc.
 */
function siteWithWildcard(array $domains): Site
{
    $server = Server::factory()->ready()->create();

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'meta' => ['testing_hostname' => ['hostname' => 'placehold-ace9a552.on-dply.cc', 'zone' => 'on-dply.cc', 'status' => 'ready']],
    ]);

    ServerWildcardCertificate::query()->create([
        'server_id' => $server->id,
        'zone' => 'on-dply.cc',
        'provider' => 'letsencrypt',
        'status' => ServerWildcardCertificate::STATUS_ACTIVE,
        'last_installed_at' => now(),
    ]);

    foreach ($domains as $domain) {
        $site->domains()->create(['hostname' => $domain, 'is_primary' => true]);
    }

    return $site->fresh();
}

test('a site on the wildcard zone alone is covered', function () {
    $site = siteWithWildcard([]);

    expect($site->coveringServerWildcard())->not->toBeNull()
        ->and($site->isCoveredByServerWildcard())->toBeTrue();
});

test('a custom domain the wildcard cannot reach is not covered', function () {
    // The reported bug: SSL badge shown next to placehold.cloud.
    $site = siteWithWildcard(['placehold.cloud']);

    // The wildcard still exists — it just does not cover everything served.
    expect($site->coveringServerWildcard())->not->toBeNull()
        ->and($site->isCoveredByServerWildcard())->toBeFalse();
});
