<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Services;

/**
 * Post-process `php artisan optimize` output for DigitalOcean Functions.
 *
 * `optimize` includes `config:cache`, which freezes `storage_path()`,
 * `logging.channels.*.path`, `view.compiled`, and other absolute paths from
 * the control-plane build directory. Those paths do not exist on the
 * function, and the action filesystem is read-only except `/tmp`.
 *
 * Routes / events / services / compiled views stay; only the config cache
 * is removed so the function boots from `config/*.php` plus the handler's
 * `/tmp` remaps.
 */
class ServerlessLaravelOptimizeCache
{
    /**
     * Drop a packaged config cache when it would bake build-host paths
     * into the zip. Returns a short log line, or empty when there was
     * nothing to do.
     */
    public function neutralize(string $workingDirectory): string
    {
        $file = rtrim($workingDirectory, '/').'/bootstrap/cache/config.php';
        if (! is_file($file)) {
            return '';
        }

        @unlink($file);

        if (is_file($file)) {
            return 'Could not remove bootstrap/cache/config.php; the Functions handler will discard build-host paths at boot.';
        }

        return 'Removed bootstrap/cache/config.php so Functions does not boot with build-host storage paths.';
    }
}
