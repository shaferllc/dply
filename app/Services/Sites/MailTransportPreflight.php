<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Contracts\RemoteShell;
use App\Models\Site;
use App\Support\Sites\MailTransportRequirements;
use Throwable;

/**
 * Warn, at deploy, when a mail binding's transport package isn't in the app.
 *
 * The binding injects MAIL_* and the config is valid, so nothing fails until the
 * first real send — which is usually a password reset, in production, seen by a
 * customer rather than an operator. Reading composer.lock out of the release we
 * just built turns that into a line in the deploy log with the fix in it.
 *
 * Never fatal, and never blocks: a site whose mail is genuinely unused should
 * not fail a deploy over an unsent email, and composer.lock is not guaranteed to
 * exist (a repo can gitignore it, or not be PHP at all).
 */
final class MailTransportPreflight
{
    /**
     * @return string|null A line for the deploy log, or null when there is
     *                     nothing to say.
     */
    public function check(Site $site, RemoteShell $ssh, string $releasePath): ?string
    {
        $site->loadMissing('bindings');

        if (MailTransportRequirements::providersFor($site) === []) {
            return null;
        }

        $installed = $this->installedPackages($ssh, $releasePath);

        // No readable lock file: say nothing rather than warn about packages we
        // could not look for. A false "missing" on every deploy trains people to
        // ignore the line, which costs more than the warning is worth.
        if ($installed === null) {
            return null;
        }

        $missing = MailTransportRequirements::missingFor($site, $installed);

        if ($missing === []) {
            return null;
        }

        $lines = ['[dply] MAIL → this site has a mail integration whose transport package is not installed in the app.'];

        foreach ($missing as $provider => $packages) {
            $lines[] = sprintf(
                '[dply]   %s needs %s — add it to the repository and redeploy:  composer require %s',
                $provider,
                implode(' + ', $packages),
                implode(' ', $packages),
            );
        }

        $lines[] = '[dply]   Mail env vars were injected as usual; sending will fail with a "class not found" until the package is there.';

        return implode("\n", $lines);
    }

    /**
     * Package names from the release's composer.lock, or null when it cannot be
     * read.
     *
     * @return list<string>|null
     */
    private function installedPackages(RemoteShell $ssh, string $releasePath): ?array
    {
        $path = rtrim($releasePath, '/').'/composer.lock';

        try {
            $raw = $ssh->exec('cat '.escapeshellarg($path).' 2>/dev/null', 30);
        } catch (Throwable) {
            return null;
        }

        $decoded = json_decode(trim((string) $raw), true);

        if (! is_array($decoded)) {
            return null;
        }

        $names = [];

        foreach (['packages', 'packages-dev'] as $section) {
            foreach ((array) ($decoded[$section] ?? []) as $package) {
                if (is_array($package) && is_string($package['name'] ?? null)) {
                    $names[] = $package['name'];
                }
            }
        }

        return $names;
    }
}
