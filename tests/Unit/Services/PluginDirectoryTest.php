<?php

declare(strict_types=1);

namespace Tests\Unit\Services\PluginDirectoryTest;

use App\Services\WordPress\PluginDirectory;
use Illuminate\Support\Facades\Http;

test('compatibility blocks on declared minimums the site misses', function () {
    $result = PluginDirectory::compatibility(
        ['requires' => '6.5', 'requires_php' => '8.2', 'tested' => ''],
        '6.4.3',
        '8.1',
    );

    expect($result['blockers'])->toHaveCount(2)
        ->and($result['warnings'])->toBe([]);
});

test('tested-up-to is compared on major.minor and only ever warns', function () {
    // 6.6 tested, site on 6.6.2 — same minor, no warning.
    expect(PluginDirectory::compatibility(['tested' => '6.6'], '6.6.2', '8.3')['warnings'])->toBe([]);

    // 6.4 tested, site on 6.6.1 — warning, never a blocker.
    $stale = PluginDirectory::compatibility(['tested' => '6.4'], '6.6.1', '8.3');
    expect($stale['warnings'])->toHaveCount(1)
        ->and($stale['blockers'])->toBe([]);
});

test('unknown site versions produce no findings rather than guesses', function () {
    $result = PluginDirectory::compatibility(['requires' => '6.5', 'requires_php' => '8.2', 'tested' => '6.0'], null, null);

    expect($result)->toBe(['blockers' => [], 'warnings' => []]);
});

test('search normalizes wordpress.org results into plain text', function () {
    Http::fake(['api.wordpress.org/*' => Http::response(['plugins' => [[
        'slug' => 'wordpress-seo',
        'name' => 'Yoast SEO &amp; more',
        'short_description' => '<b>SEO</b> for everyone',
        'author' => '<a href="https://yoast.com">Team Yoast</a>',
        'version' => '23.0',
        'rating' => 96,
        'num_ratings' => 27000,
        'active_installs' => 10000000,
        'requires' => false,
        'requires_php' => '7.4',
        'tested' => '6.6',
        'icons' => ['1x' => 'https://ps.w.org/wordpress-seo/assets/icon.png'],
    ]]])]);

    $row = app(PluginDirectory::class)->search('seo')[0];

    expect($row['name'])->toBe('Yoast SEO & more')
        ->and($row['description'])->toBe('SEO for everyone')
        ->and($row['author'])->toBe('Team Yoast')
        // wp.org reports an unset minimum as `false`, not an empty string.
        ->and($row['requires'])->toBe('')
        ->and($row['icon'])->toBe('https://ps.w.org/wordpress-seo/assets/icon.png');
});

/**
 * A wordpress.org blip must not be cached: recommendations cache for hours,
 * and caching an empty failure would blank them for the whole TTL.
 */
test('a failed lookup is not cached', function () {
    Http::fake(['api.wordpress.org/*' => Http::sequence()
        ->push('upstream error', 500)
        ->push(['plugins' => [['slug' => 'akismet', 'name' => 'Akismet']]], 200)]);

    $directory = app(PluginDirectory::class);

    expect($directory->search('spam'))->toBe([])
        ->and($directory->search('spam'))->toHaveCount(1);
});

test('terms shorter than two characters never reach the network', function () {
    Http::fake();

    expect(app(PluginDirectory::class)->search('a'))->toBe([]);

    Http::assertNothingSent();
});
