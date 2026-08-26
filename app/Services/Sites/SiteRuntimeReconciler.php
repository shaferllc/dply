<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Actions\Sites\SetSiteRuntime;
use App\Models\Site;
use InvalidArgumentException;

/**
 * Make the SITE agree with what the repository actually is.
 *
 * {@see SiteDeployStepsRuntimeReconciler} fixes the pipeline, and stops there.
 * That left half a site: a Node repo got `npm ci` instead of `composer_install`,
 * deployed green — and then served nothing, because `Site::runtime` was still
 * `php`, so nginx kept a `fastcgi_pass` vhost rooted at a `public/` directory
 * the repo does not have. A successful deploy that serves a blank page is worse
 * than a failed one; it looks finished.
 *
 * Runs post-clone, right after the step reconciler, on the same detection.
 *
 * Never fatal. A runtime switch has real preconditions — the interpreter must
 * be installed on the box, the repo must say how to start itself, there must be
 * a free internal port — and none of them are worth failing an otherwise good
 * deploy over. When one is unmet this returns a line for the deploy log saying
 * exactly which, and the site keeps the runtime it had.
 */
class SiteRuntimeReconciler
{
    public function __construct(
        private readonly SetSiteRuntime $setRuntime,
        private readonly InternalPortAllocator $ports,
    ) {}

    /**
     * Reconcile and return a human-readable line for the deploy log, or null
     * when the record already matches the repo.
     */
    public function reconcile(Site $site): ?string
    {
        $detected = $site->resolvedRuntimeAppDetection() ?? [];
        $language = strtolower(trim((string) ($detected['language'] ?? '')));
        $current = strtolower(trim((string) ($site->runtime ?? '')));

        // 'unknown' lands here whenever detection found nothing it recognises.
        // allowedRuntimes() is the gate: only a language the platform can
        // actually serve is worth moving a live site onto.
        if ($language === '' || ! in_array($language, SetSiteRuntime::allowedRuntimes(), true)) {
            return null;
        }

        if ($language === $current) {
            return null;
        }

        $changes = ['runtime' => $language];

        if (in_array($language, SetSiteRuntime::proxiedRuntimes(), true)) {
            $startCommand = trim((string) ($detected['start_command'] ?? ''))
                ?: trim((string) ($site->start_command ?? ''));

            // No start command means the repo never says how to run itself —
            // a Workers/CLI package, or a library. Switching anyway would
            // replace a page that at least renders with a permanent 502.
            if ($startCommand === '') {
                return sprintf(
                    '[dply] Runtime → detected %s but this repo declares no start command '
                    .'(no `start` script, no `main`), so the site stays on %s. '
                    .'Set one under Settings → Runtime to serve it from this server.',
                    $language,
                    $current !== '' ? $current : 'its current runtime',
                );
            }

            $changes['start_command'] = $startCommand;

            $port = (int) ($site->internal_port ?: 0);
            if ($port <= 0) {
                $allocated = $this->allocatePort((string) $site->server_id);

                if ($allocated === null) {
                    return sprintf(
                        '[dply] Runtime → detected %s but no internal port was free on this server, '
                        .'so the site stays on %s.',
                        $language,
                        $current !== '' ? $current : 'its current runtime',
                    );
                }

                $changes['internal_port'] = $allocated;
            }
        }

        try {
            $this->setRuntime->handle($site, $changes);
        } catch (InvalidArgumentException $e) {
            // Interpreter missing on the box, or no app installed yet. Both are
            // fixable by the operator and neither justifies failing the deploy.
            return '[dply] Runtime → detected '.$language.' but could not switch: '.$e->getMessage();
        }

        return sprintf(
            '[dply] Runtime → switched this site from %s to %s; the web server config is being re-applied.',
            $current !== '' ? $current : '(unset)',
            $language,
        );
    }

    /**
     * Seam for tests: InternalPortAllocator is final, so it cannot be mocked.
     */
    protected function allocatePort(string $serverId): ?int
    {
        return $this->ports->allocate($serverId);
    }
}
