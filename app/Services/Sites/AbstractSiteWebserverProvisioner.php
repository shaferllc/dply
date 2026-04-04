<?php

namespace App\Services\Sites;

use App\Enums\SiteType;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteBasicAuthUser;
use App\Services\Sites\Contracts\SiteWebserverProvisioner;
use App\Services\SshConnection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

abstract class AbstractSiteWebserverProvisioner implements SiteWebserverProvisioner
{
    public function __construct(
        protected ?SitePlaceholderPageBuilder $placeholderPageBuilder = null,
    ) {}

    protected function ensureServerReady(Site $site): Server
    {
        $server = $site->server;
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        return $server;
    }

    protected function systemSsh(Site $site): SshConnection
    {
        $server = $site->server;

        if ($server->recoverySshPrivateKey()) {
            $root = new SshConnection($server, 'root', SshConnection::ROLE_RECOVERY);
            if ($root->connect()) {
                return $root;
            }
        }

        return new SshConnection($server);
    }

    protected function writeSystemFile(SshConnection $ssh, string $remotePath, string $contents): void
    {
        if ($ssh->effectiveUsername() === 'root') {
            $ssh->putFile($remotePath, $contents);

            return;
        }

        $tmpFile = '/tmp/'.basename($remotePath).'.'.Str::random(8);
        $ssh->putFile($tmpFile, $contents);
        $out = $ssh->exec(sprintf(
            '(%s) 2>&1; printf "\nDPLY_FILE_EXIT:%%s" "$?"',
            sprintf(
                'sudo -n mkdir -p %1$s && sudo -n mv %2$s %3$s && sudo -n chown root:root %3$s && sudo -n chmod 644 %3$s',
                escapeshellarg(dirname($remotePath)),
                escapeshellarg($tmpFile),
                escapeshellarg($remotePath)
            )
        ), 60);

        if (! preg_match('/DPLY_FILE_EXIT:0\s*$/', $out)) {
            throw new \RuntimeException('Dply needs root SSH access or passwordless sudo to write '.$remotePath.'. Output: '.Str::limit($out, 1000));
        }
    }

    protected function installPlaceholderPage(Site $site, SshConnection $ssh): void
    {
        if (! in_array($site->type, [SiteType::Php, SiteType::Static], true)) {
            return;
        }

        $root = rtrim($site->effectiveDocumentRoot(), '/');
        if ($root === '') {
            return;
        }

        $out = $ssh->exec(sprintf(
            '(%s) 2>&1; printf "\nDPLY_INDEX_PLACEHOLDER_EXIT:%%s" "$?"',
            $this->privilegedCommand(
                $site->server,
                sprintf(
                    'if [ -f %1$s/index.php ] || [ -f %1$s/index.html ] || [ -f %1$s/index.htm ]; then echo exists; else echo missing; fi',
                    escapeshellarg($root)
                )
            )
        ), 60);

        if (! preg_match('/DPLY_INDEX_PLACEHOLDER_EXIT:0\s*$/', $out)) {
            throw new \RuntimeException('Unable to inspect the site web root before writing a placeholder page. Output: '.Str::limit($out, 1000));
        }

        if (str_contains($out, 'exists')) {
            return;
        }

        $builder = $this->placeholderPageBuilder ??= new SitePlaceholderPageBuilder;
        $this->writeSystemFile($ssh, $root.'/index.html', $builder->render($site));
    }

    /**
     * Writes {@see SiteSuspendedPageBuilder} HTML under {@see Site::suspendedStaticRoot()}.
     */
    protected function ensureSuspendedPage(Site $site, SshConnection $ssh): void
    {
        if (! $site->isSuspended()) {
            return;
        }

        $dir = rtrim($site->suspendedStaticRoot(), '/');
        if ($dir === '') {
            return;
        }

        $builder = new SiteSuspendedPageBuilder;
        $this->writeSystemFile($ssh, $dir.'/index.html', $builder->render($site));
    }

    protected function privilegedCommand(Server $server, string $command): string
    {
        $user = trim((string) $server->ssh_user);

        if ($user === '' || $user === 'root') {
            return $command;
        }

        return 'sudo -n bash -lc '.escapeshellarg($command);
    }

    protected function updateSiteMeta(Site $site, string $key, string $output): void
    {
        $meta = is_array($site->meta) ? $site->meta : [];
        $meta[$key] = $output;

        $site->update(['meta' => $meta]);
    }

    protected function readRemoteFile(Server $server, SshConnection $ssh, string $path): ?string
    {
        $out = $ssh->exec($this->privilegedCommand($server, 'cat '.escapeshellarg($path).' 2>/dev/null || true'), 30);
        $out = trim($out);

        return $out === '' ? null : $out;
    }

    /**
     * @param  string|null  $previousContent  null = treat as missing file
     */
    protected function restoreRemoteFile(SshConnection $ssh, Server $server, string $path, ?string $previousContent): void
    {
        if ($previousContent === null) {
            $ssh->exec($this->privilegedCommand($server, 'rm -f '.escapeshellarg($path)), 30);

            return;
        }

        $this->writeSystemFile($ssh, $path, $previousContent);
    }

    protected function configBasename(Site $site): string
    {
        return $site->webserverConfigBasename();
    }

    /**
     * Writes grouped htpasswd files under {@see Site::basicAuthStorageDirectoryOnHost()}.
     */
    protected function syncBasicAuthHtpasswdFiles(Site $site, SshConnection $ssh): void
    {
        $site->loadMissing('basicAuthUsers');
        $base = $site->basicAuthStorageDirectoryOnHost();

        $groups = $site->basicAuthUsers->groupBy(fn (SiteBasicAuthUser $u): string => $u->normalizedPath());

        foreach ($groups as $normalizedPath => $users) {
            if (! $users instanceof Collection || $users->isEmpty()) {
                continue;
            }

            $path = $site->basicAuthHtpasswdPathForNormalizedPath($normalizedPath);
            $lines = $users->map(function (SiteBasicAuthUser $user): string {
                $name = trim($user->username);

                return $name.':'.trim($user->password_hash);
            })->filter(fn (string $line): bool => $line !== ':' && ! str_starts_with($line, ':'));

            $content = $lines->implode("\n").($lines->isNotEmpty() ? "\n" : '');

            $this->writeSystemFile($ssh, $path, $content);
        }

        if ($site->basicAuthUsers->isEmpty()) {
            $ssh->exec(sprintf('rm -rf %s 2>/dev/null || true', escapeshellarg($base)), 30);
        }
    }
}
