<?php

declare(strict_types=1);

namespace App\Modules\RemoteCli\Services;

/**
 * Discriminator on RemoteCliRun rows so a Site's WP and Laravel command
 * histories can share one table while still rendering per-tab.
 */
enum Kind: string
{
    case Wp = 'wp';

    case Artisan = 'artisan';

    public function label(): string
    {
        return match ($this) {
            self::Wp => 'wp-cli',
            self::Artisan => 'php artisan',
        };
    }
}
