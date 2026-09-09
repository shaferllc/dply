<?php

declare(strict_types=1);

namespace App\Modules\WordPress\Contracts;

use App\Models\SiteGitSource;

/**
 * Puts a theme/plugin Git repo onto a WordPress site.
 *
 * Two implementations, because the two WordPress layouts disagree about what a
 * theme even is: classic WordPress wants a directory under wp-content, while
 * Bedrock wants a Composer dependency. The row in site_git_sources is identical
 * either way — only the materialization differs, which is exactly the seam.
 */
interface GitSourceMaterializer
{
    /** Clone or update the source on the server. */
    public function sync(SiteGitSource $source): void;

    /** Remove it, leaving the rest of the site untouched. */
    public function remove(SiteGitSource $source): void;

    /** Layout key this materializer handles ('classic' | 'bedrock'). */
    public function layout(): string;
}
