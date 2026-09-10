<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ThemeDirectoryTest;

use App\Services\WordPress\PluginDirectory;
use App\Services\WordPress\ThemeDirectory;
use Illuminate\Support\Facades\Http;

// Row captured from the live api.wordpress.org/themes/info/1.2/ (2026-09-10).
function liveThemeRow(): array
{
    return [
        'name' => 'Twenty Twenty-Five',
        'slug' => 'twentytwentyfive',
        'version' => '1.5',
        'preview_url' => 'https://wp-themes.com/twentytwentyfive/',
        'author' => [
            'user_nicename' => 'wordpressdotorg',
            'display_name' => 'WordPress.org',
            'author' => 'the WordPress team',
        ],
        'screenshot_url' => '//ts.w.org/wp-content/themes/twentytwentyfive/screenshot.png?ver=1.5',
        'rating' => 78,
        'num_ratings' => 13,
        'active_installs' => 1000000,
        'last_updated' => '2026-05-20',
        'description' => str_repeat('Twenty Twenty-Five emphasizes simplicity and adaptability. ', 10),
        'requires' => '6.7',
        'requires_php' => '7.2',
    ];
}

test('theme rows normalize the live response shape', function () {
    Http::fake(['api.wordpress.org/themes/*' => Http::response(['themes' => [liveThemeRow()]])]);

    $row = app(ThemeDirectory::class)->search('blog')[0];

    expect($row['author'])->toBe('WordPress.org')
        ->and($row['screenshot'])->toBe('https://ts.w.org/wp-content/themes/twentytwentyfive/screenshot.png?ver=1.5')
        ->and($row['preview_url'])->toBe('https://wp-themes.com/twentytwentyfive/')
        ->and(mb_strlen($row['description']))->toBeLessThanOrEqual(223)
        ->and($row['requires_php'])->toBe('7.2');
});

test('theme and plugin answers never share a cache entry', function () {
    Http::fake([
        'api.wordpress.org/themes/*' => Http::response(['themes' => [['slug' => 'a-theme', 'name' => 'A Theme']]]),
        'api.wordpress.org/plugins/*' => Http::response(['plugins' => [['slug' => 'a-plugin', 'name' => 'A Plugin']]]),
    ]);

    expect(app(PluginDirectory::class)->search('same')[0]['slug'])->toBe('a-plugin')
        ->and(app(ThemeDirectory::class)->search('same')[0]['slug'])->toBe('a-theme');
});

test('theme info lists versions newest first', function () {
    // The live API returns versions oldest first.
    Http::fake(['api.wordpress.org/themes/*' => Http::response(liveThemeRow() + [
        'versions' => ['1.0' => 'x', '1.6' => 'x', '1.10' => 'x'],
    ])]);

    expect(app(ThemeDirectory::class)->info('twentytwentyfive')['versions'])->toBe(['1.10', '1.6', '1.0']);
});
