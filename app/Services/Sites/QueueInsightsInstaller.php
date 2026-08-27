<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Contracts\RemoteShell;
use App\Models\Site;
use Throwable;

/**
 * Ensure the dply queue agent is installed in a release.
 *
 * Runs as a deploy step because the package has to be in the app's vendor/ for
 * Laravel to auto-discover its provider, and dply does not commit to customer
 * repositories.
 *
 * Three hard rules, learned from what this can break:
 *
 *   1. It can never fail a deploy. A registry outage is not a reason a site
 *      stops shipping, so every command ends `|| true` and the result is a log
 *      line, not an exception.
 *   2. It must be idempotent and cheap. `composer show` first, and skip
 *      entirely when the package is already there — otherwise every deploy pays
 *      a dependency resolution it does not need.
 *   3. It must be opt-in per site. Writing to a customer's composer.json is a
 *      real change to their application; it happens because someone turned it
 *      on, not because dply decided to.
 */
final class QueueInsightsInstaller
{
    /**
     * @return string|null A line for the deploy log, or null when there was
     *                     nothing to do.
     */
    public function ensure(Site $site, RemoteShell $ssh, string $releasePath): ?string
    {
        if (! $this->enabledFor($site)) {
            return null;
        }

        $package = (string) config('dply.queue_insights.package', 'dply/queue-insights');
        $constraint = (string) config('dply.queue_insights.constraint', '^1.0');
        $dir = escapeshellarg(rtrim($releasePath, '/'));

        try {
            $present = $ssh->exec(
                sprintf('cd %s && composer show %s --no-interaction 2>/dev/null | head -1 || true', $dir, escapeshellarg($package)),
                60,
            );
        } catch (Throwable $e) {
            return '[dply] QUEUE AGENT → could not check for '.$package.': '.$e->getMessage();
        }

        if (str_contains((string) $present, $package)) {
            return null;
        }

        try {
            // --no-scripts: package scripts in a customer app can do anything,
            // and a deploy is not the place to find out what. --no-interaction
            // because there is no terminal. || true is rule 1.
            $out = $ssh->exec(
                sprintf(
                    'cd %s && composer require %s:%s --no-interaction --no-scripts --no-progress 2>&1 | tail -5 || true',
                    $dir,
                    escapeshellarg($package),
                    escapeshellarg($constraint),
                ),
                240,
            );
        } catch (Throwable $e) {
            return '[dply] QUEUE AGENT → install failed, deploy continued: '.$e->getMessage();
        }

        $installed = ! str_contains(strtolower((string) $out), 'could not') && ! str_contains(strtolower((string) $out), 'failed');

        return $installed
            ? '[dply] QUEUE AGENT → installed '.$package.'. Job timing and throughput will start reporting.'
            : "[dply] QUEUE AGENT → could not install {$package}; the deploy was not affected. Depth and failures still work without it.\n".trim((string) $out);
    }

    /**
     * Opt-in per site, with a platform-wide off switch above it.
     *
     * The site flag lives in meta rather than a column: this is a preference
     * about dply's own tooling, not a property of the site's runtime.
     */
    private function enabledFor(Site $site): bool
    {
        if (! (bool) config('dply.queue_insights.enabled', false)) {
            return false;
        }

        return (bool) data_get($site->meta, 'queue_insights.enabled', false);
    }
}
