<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Site;
use App\Services\Sites\SitePhpCliGuard;
use App\Services\WorkerPools\WorkerPhpVersion;

/**
 * Resolves the PHP CLI binary a remote command should run *for a given site*.
 *
 * Bare `php` on the box is the distro default — Ubuntu 24.04 ships 8.3 — which
 * is the wrong interpreter for a site pinned to a newer version. Composer's
 * `vendor/composer/platform_check.php` fatals before the framework even boots
 * ("Your Composer dependencies require a PHP version >= 8.5.0. You are running
 * 8.3.6"), so anything that runs `php artisan` for a site under the wrong
 * binary fails in a way that looks like a broken app rather than a wrong PHP.
 *
 * Emits a shell prelude that resolves `$DPLY_PHP` and a matching binary token
 * to interpolate into the command. Resolution is best-effort: when the site's
 * version isn't installed the prelude leaves `$DPLY_PHP` as plain `php`, so a
 * missing package degrades to today's behaviour instead of "command not found".
 *
 * Distinct from {@see SitePhpCliGuard}, which *installs*
 * the version and pins `~/.dply/bin/php` for the deploy pipeline. This one is
 * read-only and safe on the request-adjacent paths (env push, CLI console).
 */
final class SitePhpCli
{
    private function __construct(
        /** Shell statements to run before {@see $binary} is referenced. Empty for non-PHP sites. */
        public readonly string $prelude,
        /** The token to invoke PHP with — `"$DPLY_PHP"` once pinned, else `php`. */
        public readonly string $binary,
    ) {}

    public static function for(?Site $site): self
    {
        $version = $site instanceof Site
            ? (new WorkerPhpVersion)->normalize($site->phpVersion())
            : null;

        if ($version === null) {
            return new self('', 'php');
        }

        return new self(
            sprintf(
                'DPLY_PHP=php; for c in /usr/bin/php%1$s php%1$s; do command -v "$c" >/dev/null 2>&1 && { DPLY_PHP="$c"; break; }; done; ',
                $version,
            ),
            '"$DPLY_PHP"',
        );
    }
}
