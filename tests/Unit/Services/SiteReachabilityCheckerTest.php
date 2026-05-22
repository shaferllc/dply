<?php

declare(strict_types=1);

namespace Tests\Unit\Services\SiteReachabilityCheckerTest;

use App\Models\Site;
use App\Models\SiteDomain;
use App\Services\Sites\SiteReachabilityChecker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

test('it marks a site reachable when localhost responds', function () {
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

    expect($result['ok'])->toBeTrue();
    expect($result['hostname'])->toBe('localhost');
    expect($result['url'])->toBe('http://localhost');
});
test('it reports an unreachable site when no hostname resolves', function () {
    Http::fake();

    $site = new Site;
    $site->setRelation('domains', new Collection([
        new SiteDomain(['hostname' => 'invalid.invalid', 'is_primary' => true]),
    ]));

    $result = (new SiteReachabilityChecker)->check($site);

    expect($result['ok'])->toBeFalse();
    expect($result['error'])->toBe('No site hostname resolves yet.');
});
