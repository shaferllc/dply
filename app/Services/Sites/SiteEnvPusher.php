<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Services\SshConnection;
use Illuminate\Support\Str;

class SiteEnvPusher
{
    public function __construct(
        protected DotEnvFileParser $parser,
        protected DotEnvFileWriter $writer,
        protected SiteEnvWriteGuard $guard,
    ) {}

    /**
     * Writes the site's `.env` to the server, composed from the editable env
     * cache PLUS the connection variables of any attached resource bindings
     * (database, redis, …). The bindings inject under the cache: a real .env
     * key the operator set still wins (that's the per-key override), but keys a
     * binding owns and the cache doesn't carry (because "adopt" moved them out
     * of the editable list) are written here so the deployed app actually
     * receives DB_HOST/REDIS_HOST/… — otherwise the binding would only ever
     * live in the deploy contract and never reach a VM's on-disk .env.
     *
     * Validates the blob via DotEnvFileParser before SSHing. Rejecting
     * malformed input here keeps the operator from pushing a file that
     * will silently break the app on the server — they get a per-line
     * error message they can fix in the UI.
     */
    /**
     * @param  string|null  $overridePath  Absolute path to write the .env to,
     *   instead of {@see Site::effectiveEnvFilePath()}. Used by the atomic
     *   deployer to seed a fresh release directory's `.env` (the git checkout
     *   has none) BEFORE build/release steps run — otherwise artisan reads
     *   Laravel's defaults (pgsql 127.0.0.1:5432) and migrations fail.
     */
    public function push(Site $site, ?string $overridePath = null): string
    {
        $server = $site->server;
        if (! $server->hostCapabilities()->supportsEnvPushToHost()) {
            throw new \RuntimeException('This host runtime does not support writing a .env file over SSH.');
        }

        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        // Derived workers have no env of their own — they deploy the parent
        // app's env with a small override set applied. effectiveEnvFileContent()
        // returns own content verbatim for standalone sites.
        $content = $site->effectiveEnvFileContent();
        $parsed = $this->parser->parse($content);
        if ($parsed['errors'] !== []) {
            throw new \RuntimeException('.env has parse errors — fix and retry: '.implode('; ', $parsed['errors']));
        }

        // Compose attached-resource bindings under the cache (cache/override
        // wins). The merged map is both what gets written AND what we validate —
        // a binding-supplied DB_HOST/REDIS_HOST shouldn't read as "missing".
        $bindingEnv = $this->bindingEnv($site);
        $mergedVars = $bindingEnv !== []
            ? array_merge($bindingEnv, $parsed['variables'])
            : $parsed['variables'];

        // Test it's valid first. Only on real operator pushes ($overridePath is
        // null) — the deploy-seeding path runs the pipeline's own required-env
        // gate and seeds before the release is built (nothing to live-test).
        // Static danger check here (fail fast, no round-trip); the live boot
        // test runs server-side just before the swap, inside writeViaTmp().
        $validateBeforeWrite = $overridePath === null;
        if ($validateBeforeWrite) {
            $this->guard->assertSafeToWrite($mergedVars);
        }

        // When a binding contributed keys the cache lacks, re-render the file so
        // they're physically written to the server's .env.
        if ($bindingEnv !== [] && $mergedVars !== $parsed['variables']) {
            $content = $this->writer->render($mergedVars, $parsed['comments']);
        }

        $path = $overridePath ?? $site->effectiveEnvFilePath();
        $parent = dirname($path);
        $ssh = new SshConnection($server);
        $tmp = '/tmp/dply-env-'.Str::lower(Str::random(20));

        try {
            return $this->writeViaTmp($ssh, $site, $content, $tmp, $path, $parent, $validateBeforeWrite, $this->activeAppDir($site));
        } finally {
            // Defence-in-depth: the success path rm's $tmp inside the sudo
            // script below, but any throw before that point would otherwise
            // leave a world-readable (644) copy of the .env in /tmp. The tmp
            // is owned by the SSH user (putFile created it), so this plain rm
            // can remove it. Best-effort — never mask the original error.
            try {
                $ssh->exec('rm -f '.escapeshellarg($tmp));
            } catch (\Throwable) {
                // ignore — cleanup is best-effort
            }
        }
    }

    /**
     * Stages the env blob to $tmp and copies it into place as root. Split out
     * so {@see push()} can guarantee tmp cleanup in a finally regardless of
     * where this throws.
     */
    private function writeViaTmp(
        SshConnection $ssh,
        Site $site,
        string $content,
        string $tmp,
        string $path,
        string $parent,
        bool $operatorPush = true,
        string $activeDir = '',
    ): string {
        $ssh->putFile($tmp, $content);
        // Stage the tmp file world-readable so root's `cp` (below) can read
        // it regardless of who owns /tmp/<file>. Exit-code-checked so a
        // sandbox/quota error can't fail silently.
        $stageOut = $ssh->exec('chmod 644 '.escapeshellarg($tmp));
        $this->assertExitOk($ssh, $stageOut, 'staging tmp file');

        // Live boot test: confirm the deployed app can actually load this
        // candidate .env before we swap it into place. Throws on a boot
        // failure (leaving the live .env untouched); gracefully skips when
        // there's no built app to boot. Operator pushes only — the deploy
        // seeding path has no built release to test against yet.
        if ($operatorPush && $activeDir !== '') {
            $this->guard->assertBootsOnServer($ssh, $activeDir, $tmp);
        }

        // Unified flow: ALWAYS sudo. Whether the destination is inside the
        // docroot (default) or somewhere outside (e.g. /etc/dply/<slug>.env),
        // running as root sidesteps the "who owns this directory?" question.
        // The previous user-scoped flow (running as the site user via
        // wrapRemoteExec) frequently failed with "Permission denied" when
        // the site's docroot was owned by a per-site Linux user different
        // from the SSH login user.
        //
        // We sudo to:
        //   1. mkdir -p $parent (root creates the tree if missing)
        //   2. cp $tmp $path (root writes the file)
        //   3. chown to root:<site-user-primary-group> so the site's runtime
        //      reads it via group membership (no access for other site users)
        //   4. chmod 640 to lock down world access
        //   5. rm the staged tmp
        //
        // `id -gn <user>` resolves the runtime's primary group dynamically.
        // If the site has no effective system user, fall back to root (the
        // file ends up root:root 640 — only root can read, which is fine for
        // root-run runtimes and surfaces clearly when it isn't).
        $siteUser = trim($site->effectiveSystemUser($site->server));
        if ($siteUser === '') {
            $siteUser = 'root';
        }
        // System usernames are [a-z0-9_-] — safe to embed directly inside a
        // double-quoted string. escapeshellarg would add single quotes that
        // land inside the outer double quotes and corrupt the chown argument.
        $safeUser = preg_replace('/[^a-zA-Z0-9_\-]/', '', $siteUser);
        $inner = sprintf(
            'set -e; mkdir -p %s; cp %s %s; chown "%s:$(id -gn %s)" %s; chmod 640 %s; rm -f %s',
            escapeshellarg($parent),
            escapeshellarg($tmp),
            escapeshellarg($path),
            $safeUser,
            $safeUser,
            escapeshellarg($path),
            escapeshellarg($path),
            escapeshellarg($tmp),
        );
        $wrapped = 'sudo -n bash -lc '.escapeshellarg($inner);

        $output = $ssh->exec($wrapped, 120);
        $this->assertExitOk($ssh, $output, 'writing .env to '.$path);

        // After a successful push, the cache reflects "what's on disk per
        // the most recent push" — but the bits came from the operator's
        // edits, not from a server read. The "edited :time" pill is the
        // accurate label for this state. Skip when seeding a release dir
        // during deploy (override path) — that's not an operator edit.
        if ($operatorPush) {
            $site->forceFill(['env_cache_origin' => 'local-edit'])->save();
        }

        return $path;
    }

    /**
     * The directory of the running app the live boot test should exercise:
     * the `current` symlink for atomic deploys, else the repository root.
     * Mirrors {@see SiteEnvRuntimeApplier}. Empty string when the site has no
     * resolvable path yet (skips the live test).
     */
    private function activeAppDir(Site $site): string
    {
        $base = rtrim($site->effectiveRepositoryPath(), '/');
        if ($base === '') {
            return '';
        }

        return $site->isAtomicDeploys() ? $base.'/current' : $base;
    }

    /**
     * Flattened connection variables from every attached resource binding,
     * later keys winning ties between bindings (rare). Returns [] when the site
     * has no bindings — leaving the cache content untouched.
     *
     * @return array<string, string>
     */
    private function bindingEnv(Site $site): array
    {
        // A derived worker inherits its parent app's resources, so the
        // connection vars come from the parent's bindings, not the worker's own
        // (which are empty). Standalone sites resolve to themselves.
        $source = $site->resourceSourceSite();

        $env = [];
        $source->loadMissing('bindings');
        foreach ($source->bindings as $binding) {
            foreach ($binding->connectionEnv() as $key => $value) {
                $env[(string) $key] = (string) $value;
            }
        }

        return $env;
    }

    /**
     * Throws if the last `$ssh->exec` returned a non-zero exit code,
     * including any captured output as context. {@see SshConnection::exec()}
     * doesn't check the exit status, so without this guard a failed cp /
     * chown / sudo silently looks like success.
     */
    private function assertExitOk(SshConnection $ssh, string $output, string $what): void
    {
        $exit = $ssh->lastExecExitCode();
        if ($exit === null || $exit === 0) {
            return;
        }

        $detail = trim($output);
        if ($detail === '') {
            $detail = '(no output captured)';
        }
        throw new \RuntimeException(sprintf('Failed %s (exit %d): %s', $what, $exit, $detail));
    }
}
