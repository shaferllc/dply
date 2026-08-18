<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Sites;

use App\Enums\SiteType;
use App\Models\Server;
use App\Models\Site;
use App\Support\Sites\SiteShowViewData;

test('wildcard tls sits between testing hostname and writing site config', function () {
    config(['sites.wildcard_testing_ssl' => true]);

    $site = Site::make(['runtime' => 'php', 'type' => SiteType::Php]);
    $site->setRelation('server', Server::make(['meta' => ['webserver' => 'nginx']]));

    $steps = SiteShowViewData::byoStatusSteps($site, 'waiting_for_wildcard_tls', false);
    $keys = array_keys($steps);

    expect($steps['waiting_for_wildcard_tls'])->toBe(__('Issuing wildcard TLS'))
        ->and(array_search('waiting_for_wildcard_tls', $keys, true))->toBe(4)
        ->and(array_search('writing_site_config', $keys, true))->toBe(5)
        ->and(array_search('waiting_for_wildcard_tls', $keys, true))->not->toBeFalse();
});

test('wildcard tls stays in the journey before issuance starts so the count does not jump', function () {
    config(['sites.wildcard_testing_ssl' => true]);

    $site = Site::make(['runtime' => 'php', 'type' => SiteType::Php]);
    $site->setRelation('server', Server::make(['meta' => ['webserver' => 'nginx']]));

    $queued = array_keys(SiteShowViewData::byoStatusSteps($site, 'queued', false));
    $waiting = array_keys(SiteShowViewData::byoStatusSteps($site, 'waiting_for_wildcard_tls', false));

    expect($queued)->toBe($waiting)
        ->and($queued)->toContain('waiting_for_wildcard_tls');
});

test('plain caddy omits wildcard tls unless the site is already waiting on it', function () {
    config(['sites.wildcard_testing_ssl' => true]);

    $site = Site::make(['runtime' => 'php', 'type' => SiteType::Php]);
    $site->setRelation('server', Server::make(['meta' => ['webserver' => 'caddy']]));

    expect(array_keys(SiteShowViewData::byoStatusSteps($site, 'queued', false)))
        ->not->toContain('waiting_for_wildcard_tls');

    expect(array_search(
        'waiting_for_wildcard_tls',
        array_keys(SiteShowViewData::byoStatusSteps($site, 'waiting_for_wildcard_tls', false)),
        true,
    ))->not->toBeFalse();
});
