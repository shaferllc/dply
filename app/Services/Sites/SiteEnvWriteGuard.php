<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Services\SshConnection;
use Illuminate\Support\Str;

/**
 * The "test it's valid first" gate that runs immediately BEFORE a composed
 * `.env` is written into place on a server. Its whole job is to never let a
 * push clobber a working `.env` with a definitively-broken one (the prod-reverb
 * / empty-APP_KEY class of incident).
 *
 * Two checks, cheapest first:
 *
 *   1. STATIC — run {@see SiteEnvValidator} on the composed KEY=>value map
 *      in-process and refuse the write if ANY danger-level finding exists
 *      (the "app 500s on every request" set: empty/invalid APP_KEY, a
 *      broadcaster with null credentials, a database with no host, …).
 *      No round-trip; fails fast.
 *
 *   2. LIVE — stage the candidate `.env` on the box and actually boot the
 *      deployed app against it (`php artisan config:cache` into a throwaway
 *      cache path, under a throwaway `--env`), confirming the framework can
 *      load the file and build config without fataling. Non-mutating: the live
 *      `.env`, the real config cache, and the running app are never touched.
 *      Gracefully skips when there's no built app to boot (fresh site, no
 *      vendor/, or the docroot isn't writable by the SSH user).
 *
 * Distinct from {@see SiteEnvRuntimeApplier}, which guards the config-cache
 * REBUILD *after* the file is already on disk. This guard runs first, so a
 * broken edit is rejected before it ever lands.
 */
final class SiteEnvWriteGuard
{
    public function __construct(
        private SiteEnvValidator $validator,
    ) {}

    /**
     * The danger-level findings (only) for a composed env map.
     *
     * @param  array<string, string>  $vars
     * @return list<array{level: string, key: ?string, message: string}>
     */
    public function dangers(array $vars): array
    {
        return array_values(array_filter(
            $this->validator->validate($vars),
            static fn (array $f): bool => ($f['level'] ?? '') === 'danger',
        ));
    }

    /**
     * Static gate: throw if the composed env carries any danger-level finding.
     * The message lists every offending key so the operator can fix it in the
     * editor and retry.
     *
     * @param  array<string, string>  $vars
     */
    public function assertSafeToWrite(array $vars): void
    {
        $dangers = $this->dangers($vars);
        if ($dangers === []) {
            return;
        }

        $lines = array_map(
            static fn (array $f): string => '  • '.($f['key'] !== null && $f['key'] !== '' ? $f['key'].': ' : '').$f['message'],
            $dangers,
        );

        throw new \RuntimeException(
            __('.env failed validation — refusing to write it (this would break the app on every request):')
            ."\n".implode("\n", $lines)
        );
    }

    /**
     * Live gate: boot the deployed app at $activeDir against the candidate
     * `.env` already staged at $stagedTmpPath, confirming it loads and config
     * builds without a fatal — BEFORE the file is swapped into place.
     *
     * Isolated and non-mutating:
     *   - the candidate is loaded under a throwaway `--env` (`.env.<token>`),
     *     never the live `.env`;
     *   - `APP_CONFIG_CACHE` redirects the cache write to a throwaway path, so
     *     the real bootstrap/cache/config.php that's serving traffic is never
     *     overwritten;
     *   - both throwaway files are removed regardless of outcome.
     *
     * Skips (does not block) when there's nothing to boot — no artisan, no
     * vendor/, or the docroot isn't writable by the SSH user. The static gate
     * still applies in those cases.
     *
     * @throws \RuntimeException if the app fails to boot/build config with the
     *                           candidate env (the captured artisan output is included).
     */
    public function assertBootsOnServer(SshConnection $ssh, string $activeDir, string $stagedTmpPath): void
    {
        $token = Str::lower(Str::random(12));
        $envName = 'dplyvalidate-'.$token;
        $cfgPath = '/tmp/dply-cfgtest-'.$token.'.php';
        $outPath = '/tmp/dply-cfgtest-'.$token.'.out';
        $envFile = '.env.'.$envName;

        $script = implode(' ', [
            'set -u;',
            'cd '.escapeshellarg($activeDir).' 2>/dev/null || { echo DPLY_ENVTEST_SKIP_NOCD; exit 0; };',
            // Nothing to boot yet (fresh site / un-built release) → skip, don't block.
            'if [ ! -f artisan ] || [ ! -f vendor/autoload.php ]; then echo DPLY_ENVTEST_SKIP_NOAPP; exit 0; fi;',
            // Docroot not writable by the SSH user (e.g. root:www-data flat layout) → skip.
            'cp '.escapeshellarg($stagedTmpPath).' '.escapeshellarg($envFile).' 2>/dev/null || { echo DPLY_ENVTEST_SKIP_STAGE; exit 0; };',
            // Boot the framework + build config under the throwaway env and cache path.
            'APP_CONFIG_CACHE='.escapeshellarg($cfgPath).' php artisan config:cache --env='.escapeshellarg($envName).' > '.escapeshellarg($outPath).' 2>&1;',
            'RC=$?;',
            'rm -f '.escapeshellarg($envFile).' '.escapeshellarg($cfgPath).';',
            'if [ "$RC" -ne 0 ]; then echo DPLY_ENVTEST_FAIL; cat '.escapeshellarg($outPath).' 2>/dev/null; rm -f '.escapeshellarg($outPath).'; exit 0; fi;',
            'rm -f '.escapeshellarg($outPath).';',
            'echo DPLY_ENVTEST_OK;',
        ]);

        $output = $ssh->exec('bash -lc '.escapeshellarg($script), 120);

        if (! str_contains($output, 'DPLY_ENVTEST_FAIL')) {
            // OK or any SKIP_* marker — the candidate either booted or couldn't
            // be live-tested. Either way, don't block on the live gate.
            return;
        }

        // Strip our marker line; what remains is the artisan boot output.
        $detail = trim(str_replace('DPLY_ENVTEST_FAIL', '', $output));
        if ($detail === '') {
            $detail = __('(no output captured)');
        }

        throw new \RuntimeException(
            __('.env failed the live boot test — refusing to write it. The app could not load this configuration:')
            ."\n".$detail
        );
    }
}
