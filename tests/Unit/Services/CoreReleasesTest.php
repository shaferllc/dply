<?php

declare(strict_types=1);

namespace Tests\Unit\Services\CoreReleasesTest;

use App\Services\WordPress\CoreReleases;
use Illuminate\Support\Facades\Http;

// Offer shape captured from the live api.wordpress.org/core/version-check/1.7/ (2026-09-10).
function coreOffers(): array
{
    return ['offers' => [
        ['response' => 'upgrade', 'current' => '7.1', 'php_version' => '7.4', 'mysql_version' => '5.5.5'],
        ['response' => 'autoupdate', 'current' => '7.1', 'php_version' => '7.4', 'mysql_version' => '5.5.5'],
        ['response' => 'autoupdate', 'current' => '6.8.8', 'php_version' => '7.2.24', 'mysql_version' => '5.5.5'],
        ['response' => 'autoupdate', 'current' => '5.9.16', 'php_version' => '5.6.20', 'mysql_version' => '5.0'],
    ]];
}

test('branches are one release per branch, newest first, above the floor', function () {
    Http::fake(['api.wordpress.org/core/version-check/*' => Http::response(coreOffers())]);

    expect(array_column(app(CoreReleases::class)->branches(), 'version'))->toBe(['7.1', '6.8.8'])
        ->and(app(CoreReleases::class)->branches()[1]['php'])->toBe('7.2.24');
});

test('status reads latest, outdated and insecure from stable-check', function () {
    Http::fake(['api.wordpress.org/core/stable-check/*' => Http::response(['7.1' => 'latest', '6.8.8' => 'outdated', '6.0' => 'insecure'])]);

    $releases = app(CoreReleases::class);
    expect($releases->status('6.0'))->toBe('insecure')
        ->and($releases->status('7.1'))->toBe('latest')
        ->and($releases->status('9.9'))->toBeNull();
});

test('a failed fetch is not cached', function () {
    Http::fakeSequence()->push('', 500)->push(coreOffers());

    expect(app(CoreReleases::class)->branches())->toBe([])
        ->and(app(CoreReleases::class)->branches())->toHaveCount(2);
});

test('compatibility blocks on php and warns on downgrades and old branches', function () {
    expect(CoreReleases::compatibility(['version' => '7.1', 'php' => '7.4'], '6.8.8', '7.2')['blockers'])->toHaveCount(1);

    $downgrade = CoreReleases::compatibility(['version' => '6.8.8', 'php' => '7.2.24'], '7.1', '8.3');
    expect($downgrade['blockers'])->toBe([])
        ->and($downgrade['warnings'])->toHaveCount(1);

    // Below 6.4 on PHP 8.2+: the downgrade warning plus the PHP one.
    expect(CoreReleases::compatibility(['version' => '6.2.11', 'php' => '5.6.20'], '7.1', '8.3')['warnings'])->toHaveCount(2);
});
