<?php

namespace App\Services\Sites;

use App\Models\Site;
use App\Services\SshConnection;

class SiteEnvPusher
{
    public function __construct(
        protected SiteDotEnvComposer $composer
    ) {}

    /**
     * Writes composed .env (raw draft + key/value vars). Optionally updates the draft first.
     */
    public function push(Site $site, ?string $draftEnvContent = null): string
    {
        $server = $site->server;
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
        $ssh->putFile($path, $content);
        $ssh->exec('chmod 640 '.escapeshellarg($path).' 2>/dev/null || chmod 600 '.escapeshellarg($path));

        return $path;
    }
}
