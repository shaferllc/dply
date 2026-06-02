<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Services\SshConnection;
use Illuminate\Support\Str;

class SiteEnvPusher
{
    public function __construct(
        protected DotEnvFileParser $parser,
    ) {}

    /**
     * Writes the site's encrypted env cache to the server's `.env` file
     * verbatim. The cache IS the desired contents — we don't compose
     * project-level vars in here anymore (Laravel reads project defaults
     * separately; the deployment contract is what merges them).
     *
     * Validates the blob via DotEnvFileParser before SSHing. Rejecting
     * malformed input here keeps the operator from pushing a file that
     * will silently break the app on the server — they get a per-line
     * error message they can fix in the UI.
     */
    public function push(Site $site): string
    {
        $server = $site->server;
        if (! $server->hostCapabilities()->supportsEnvPushToHost()) {
            throw new \RuntimeException('This host runtime does not support writing a .env file over SSH.');
        }

        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $content = (string) ($site->env_file_content ?? '');
        $parsed = $this->parser->parse($content);
        if ($parsed['errors'] !== []) {
            throw new \RuntimeException('.env has parse errors — fix and retry: '.implode('; ', $parsed['errors']));
        }

        $path = $site->effectiveEnvFilePath();
        $parent = dirname($path);
        $ssh = new SshConnection($server);
        $tmp = '/tmp/dply-env-'.Str::lower(Str::random(20));

        try {
            return $this->writeViaTmp($ssh, $site, $content, $tmp, $path, $parent);
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
    ): string {
        $ssh->putFile($tmp, $content);
        // Stage the tmp file world-readable so root's `cp` (below) can read
        // it regardless of who owns /tmp/<file>. Exit-code-checked so a
        // sandbox/quota error can't fail silently.
        $stageOut = $ssh->exec('chmod 644 '.escapeshellarg($tmp));
        $this->assertExitOk($ssh, $stageOut, 'staging tmp file');

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
        $inner = sprintf(
            'set -e; mkdir -p %s; cp %s %s; chown "root:$(id -gn %s)" %s; chmod 640 %s; rm -f %s',
            escapeshellarg($parent),
            escapeshellarg($tmp),
            escapeshellarg($path),
            escapeshellarg($siteUser),
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
        // accurate label for this state.
        $site->forceFill(['env_cache_origin' => 'local-edit'])->save();

        return $path;
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
