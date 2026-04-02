<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Services\SshConnection;
use Illuminate\Support\Str;

class SiteEnvPusher
{
    public function __construct(
        protected SiteDotEnvComposer $composer,
        protected SiteScopedCommandWrapper $commandWrapper,
    ) {}

    /**
     * Writes composed .env (raw draft + key/value vars). Optionally updates the draft first.
     */
    public function push(Site $site, ?string $draftEnvContent = null): string
    {
        $server = $site->server;
        if (! $server->hostCapabilities()->supportsEnvPushToHost()) {
            throw new \RuntimeException('This host runtime does not support writing a .env file over SSH.');
        }

        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        if ($draftEnvContent !== null) {
            $site->update(['env_file_content' => $draftEnvContent]);
            $site->refresh();
        }

        $site->loadMissing('environmentVariables');
        $content = $this->composer->compose($site);
        $path = rtrim($site->effectiveEnvDirectory(), '/').'/.env';
        $ssh = new SshConnection($server);
        $tmp = '/tmp/dply-env-'.Str::lower(Str::random(20));
        $ssh->putFile($tmp, $content);
        $ssh->exec('chmod 644 '.escapeshellarg($tmp));
        $inner = 'cp '.escapeshellarg($tmp).' '.escapeshellarg($path).' && (chmod 640 '.escapeshellarg($path).' 2>/dev/null || chmod 600 '.escapeshellarg($path).') && rm -f '.escapeshellarg($tmp);
        $wrapped = $this->commandWrapper->wrapRemoteExec($site, $inner);
        $ssh->exec($wrapped, 120);

        return $path;
    }
}
