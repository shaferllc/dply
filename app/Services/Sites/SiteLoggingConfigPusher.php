<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Models\SiteBinding;
use App\Services\Logging\LoggingConfigGenerator;
use App\Services\Logging\LoggingSpec;
use App\Services\SshConnection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Overlays dply's generated `config/logging.php` into a release (Phase 2 of the
 * managed-logging design). dply owns that file: it's regenerated from the
 * site's logging binding spec and written into each release next to `.env`, and
 * the repo's committed copy is overwritten in the deployed tree (never in git).
 *
 * Safety is the whole point here — a bad logging config fatals the app on every
 * boot. So {@see self::apply()} runs the three Q9 gates before the file goes
 * live:
 *   1. `php -l` on the generated source, in dply (syntax).
 *   2. a resolution probe ON THE BOX that boots the app and constructs every
 *      configured channel via the log manager (catches missing handler classes
 *      — the escape-hatch failure mode — and bad constructor args).
 *   3. only on a clean probe is the file moved into place.
 * Called AFTER build (so vendor/ exists for the probe) and BEFORE activate, so a
 * failure aborts the deploy before the symlink flips — the prior release keeps
 * serving and the site never 500s.
 */
class SiteLoggingConfigPusher
{
    public function __construct(
        protected LoggingConfigGenerator $generator,
    ) {}

    /**
     * @return array{managed: bool, log: string}
     */
    /** @return array<string, mixed> */
    public function apply(Site $site, SshConnection $ssh, string $buildDir): array
    {
        $binding = $this->managedBinding($site);
        if (! $binding instanceof SiteBinding) {
            return ['managed' => false, 'log' => "[dply] LOGGING → no managed logging binding; leaving repo's config/logging.php\n"];
        }

        $spec = ($binding->config );
        $content = $this->generator->generate($spec);

        // Gate 1 — syntax, in dply (no round-trip to the box needed).
        $this->lintLocally($content);

        $buildDir = rtrim($buildDir, '/');
        $target = $buildDir.'/config/logging.php';
        $tmpFile = '/tmp/dply-logging-'.Str::lower(Str::random(20)).'.php';
        $tmpProbe = '/tmp/dply-logprobe-'.Str::lower(Str::random(20)).'.php';

        try {
            $ssh->putFile($tmpFile, $content);
            $ssh->putFile($tmpProbe, $this->probeScript());
            $ssh->exec('chmod 644 '.escapeshellarg($tmpFile).' '.escapeshellarg($tmpProbe));

            // Gate 2 — boot the app against the candidate and build every
            // channel. Runs as the SSH login user (the runtime identity), the
            // same way build/release steps run, so it reads the just-written
            // .env and resolves classes against this release's vendor/.
            $probeCmd = sprintf(
                'cd %s && php %s %s %s 2>&1; printf "\nDPLY_PROBE_EXIT:%%s" "$?"',
                escapeshellarg($buildDir),
                escapeshellarg($tmpProbe),
                escapeshellarg($buildDir),
                escapeshellarg($tmpFile),
            );
            $probeOut = $ssh->exec($probeCmd, 120);
            $exit = $this->parseProbeExit($probeOut);
            if ($exit !== 0) {
                throw new RuntimeException(
                    "Generated config/logging.php was rejected by the deploy probe (exit {$exit}) — ".
                    "the release was NOT activated. Detail:\n".trim($this->stripExitMarker($probeOut))
                );
            }

            // Gate 3 — move into place as root, owned like the rest of the
            // release (mirrors SiteEnvPusher's chown/chmod), so PHP-FPM reads it.
            $this->installAsRoot($ssh, $site, $tmpFile, $target);

            return ['managed' => true, 'log' => sprintf(
                "[dply] LOGGING → generated config/logging.php (%d channel(s)) probed OK and written to %s\n",
                is_array($spec['channels'] ?? null) ? count($spec['channels']) : 0,
                $target,
            )];
        } finally {
            // Best-effort cleanup; never mask the original error.
            try {
                $ssh->exec('rm -f '.escapeshellarg($tmpFile).' '.escapeshellarg($tmpProbe));
            } catch (\Throwable) {
                // ignore
            }
        }
    }

    /**
     * The site's logging binding iff it carries a v2 spec dply should generate
     * from. A binding still on the legacy `{provider}` shape is left to the
     * env-only path (no file overlay).
     */
    private function managedBinding(Site $site): ?SiteBinding
    {
        // A derived worker inherits its parent app's logging binding.
        $source = $site->resourceSourceSite();
        $source->loadMissing('bindings');
        $binding = $source->bindings->firstWhere('type', 'logging');
        if (! $binding instanceof SiteBinding) {
            return null;
        }

        return LoggingSpec::isV2(($binding->config )) ? $binding : null;
    }

    private function lintLocally(string $content): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'dply-log-lint-').'.php';
        try {
            file_put_contents($tmp, $content);
            $output = [];
            $exit = 0;
            exec('php -l '.escapeshellarg($tmp).' 2>&1', $output, $exit);
            if ($exit !== 0) {
                throw new RuntimeException("Generated config/logging.php failed php -l:\n".implode("\n", $output));
            }
        } finally {
            @unlink($tmp);
        }
    }

    private function installAsRoot(SshConnection $ssh, Site $site, string $tmp, string $target): void
    {
        $siteUser = trim($site->effectiveSystemUser($site->server));
        if ($siteUser === '') {
            $siteUser = 'root';
        }
        $safeUser = preg_replace('/[^a-zA-Z0-9_\-]/', '', $siteUser) ?? 'root';

        $inner = sprintf(
            'set -e; mkdir -p %s; cp %s %s; chown "%s:$(id -gn %s)" %s; chmod 640 %s',
            escapeshellarg(dirname($target)),
            escapeshellarg($tmp),
            escapeshellarg($target),
            $safeUser,
            $safeUser,
            escapeshellarg($target),
            escapeshellarg($target),
        );
        $out = $ssh->exec('sudo -n bash -lc '.escapeshellarg($inner), 60);
        if (($ssh->lastExecExitCode() ?? 0) !== 0) {
            throw new RuntimeException('Failed to install config/logging.php: '.trim($out));
        }
    }

    private function parseProbeExit(string $output): int
    {
        if (preg_match('/DPLY_PROBE_EXIT:(\d+)/', $output, $m)) {
            return (int) $m[1];
        }

        // No marker → the php process never reached the printf (fatal/timeout).
        // Treat as failure so we never activate on an unverified file.
        return 1;
    }

    private function stripExitMarker(string $output): string
    {
        return (string) preg_replace('/\nDPLY_PROBE_EXIT:\d+\s*$/', '', $output);
    }

    /**
     * The on-box probe: boot the app, swap in the candidate logging config, and
     * construct every channel via the log manager. Non-zero exit = a channel
     * could not be built (missing class, bad args) and the deploy must abort.
     */
    private function probeScript(): string
    {
        return <<<'PHP'
<?php
// dply managed-logging deploy probe — boots the app and builds every channel
// from the CANDIDATE config/logging.php. Exit 0 = all channels resolve.
$base = $argv[1] ?? '';
$cand = $argv[2] ?? '';
try {
    chdir($base);
    require $base.'/vendor/autoload.php';
    $app = require $base.'/bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    $candidate = require $cand;
    if (! is_array($candidate) || empty($candidate['channels']) || ! is_array($candidate['channels'])) {
        fwrite(STDERR, 'PROBE: candidate has no channels');
        exit(2);
    }

    $app['config']->set('logging', $candidate);
    $log = $app->make('log');
    if (method_exists($log, 'forgetChannels')) {
        $log->forgetChannels();
    }

    $errors = [];
    foreach (array_keys($candidate['channels']) as $name) {
        if ($name === 'emergency') {
            continue; // path-only fallback, no driver to build
        }
        try {
            $log->channel($name);
        } catch (\Throwable $e) {
            $errors[] = $name.': '.$e->getMessage();
        }
    }
    $default = $candidate['default'] ?? null;
    if (is_string($default) && $default !== '') {
        try {
            $log->channel($default);
        } catch (\Throwable $e) {
            $errors[] = 'default('.$default.'): '.$e->getMessage();
        }
    }

    if ($errors !== []) {
        fwrite(STDERR, 'PROBE_FAIL: '.implode(' | ', $errors));
        exit(1);
    }
    fwrite(STDOUT, 'PROBE_OK');
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, 'PROBE_BOOT_FAIL: '.$e->getMessage());
    exit(1);
}
PHP;
    }
}
