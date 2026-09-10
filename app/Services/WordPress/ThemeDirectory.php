<?php

declare(strict_types=1);

namespace App\Services\WordPress;

use Illuminate\Support\Str;

/**
 * Read-only client for the WordPress.org theme directory.
 *
 * Same API shape, caching and failure rules as {@see PluginDirectory}; the rows
 * differ. Verified against the live API: `author` is an object, the text field
 * is `description` (full length, not a short one), `screenshot_url` is
 * protocol-relative, and themes declare no "tested up to".
 */
final class ThemeDirectory extends PluginDirectory
{
    protected const KIND = 'theme';

    protected const FIELDS = [
        'description', 'rating', 'num_ratings', 'active_installs',
        'requires', 'requires_php', 'last_updated', 'screenshot_url', 'version', 'extended_author',
    ];

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    protected function normalize(array $p): array
    {
        $author = $p['author'] ?? '';
        if (is_array($author)) {
            $author = $author['display_name'] ?? $author['user_nicename'] ?? '';
        }

        $theme = parent::normalize(['author' => $author, 'short_description' => $p['description'] ?? ''] + $p);
        $theme['description'] = Str::limit($theme['description'], 220);

        $screenshot = (string) ($p['screenshot_url'] ?? '');
        if (str_starts_with($screenshot, '//')) {
            $screenshot = 'https:'.$screenshot;
        }
        $theme['screenshot'] = str_starts_with($screenshot, 'https://') ? $screenshot : '';

        $preview = (string) ($p['preview_url'] ?? '');
        $theme['preview_url'] = str_starts_with($preview, 'https://') ? $preview : '';

        return $theme;
    }
}
