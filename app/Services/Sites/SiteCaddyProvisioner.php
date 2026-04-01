<?php

namespace App\Services\Sites;

use App\Enums\SiteType;
use App\Models\Site;
use App\Services\SshConnection;
use Illuminate\Support\Str;

class SiteCaddyProvisioner
{
    public function provision(Site $site): string
    {
        $server = $site->server;
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $config = $this->build($site);
        $configFile = '/etc/caddy/sites-enabled/'.$site->nginxConfigBasename().'.caddy';
        $importLine = 'import /etc/caddy/sites-enabled/*.caddy';

        $ssh = $this->systemSsh($site);
        $this->writeSystemFile($ssh, $configFile, $config);
        $out = $ssh->exec(sprintf(
            '(%s) 2>&1; printf "\nDPLY_CADDY_EXIT:%%s" "$?"',
            $this->privilegedCommand(
                $server,
                sprintf(
                    'mkdir -p /etc/caddy/sites-enabled && touch /etc/caddy/Caddyfile && (grep -Fqx %1$s /etc/caddy/Caddyfile || printf "\n%%s\n" %2$s >> /etc/caddy/Caddyfile) && caddy validate --config /etc/caddy/Caddyfile && (systemctl reload caddy 2>/dev/null || service caddy reload 2>/dev/null || systemctl restart caddy)',
                    escapeshellarg($importLine),
                    escapeshellarg($importLine)
                )
            )
        ), 120);

        $caddyOk = (bool) preg_match('/DPLY_CADDY_EXIT:0\s*$/', $out);
        if (! $caddyOk) {
            throw new \RuntimeException('Caddy validate or reload failed. Output: '.Str::limit($out, 2000));
        }

        $meta = is_array($site->meta) ? $site->meta : [];
        $meta['caddy_last_output'] = $out;

        $site->update([
            'meta' => $meta,
        ]);

        return $out;
    }

    private function systemSsh(Site $site): SshConnection
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

    private function writeSystemFile(SshConnection $ssh, string $remotePath, string $contents): void
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

    private function privilegedCommand(\App\Models\Server $server, string $command): string
    {
        $user = trim((string) $server->ssh_user);

        if ($user === '' || $user === 'root') {
            return $command;
        }

        return 'sudo -n bash -lc '.escapeshellarg($command);
    }

    private function build(Site $site): string
    {
        $site->loadMissing('domains');

        $hostnames = $site->domains->pluck('hostname')->filter()->unique()->values();
        if ($hostnames->isEmpty()) {
            throw new \InvalidArgumentException('Add at least one domain before installing Caddy.');
        }

        $hosts = $hostnames->implode(', ');
        $root = $site->effectiveDocumentRootForNginx();
        $phpSock = str_replace(
            '{version}',
            $site->php_version ?? '8.3',
            config('sites.php_fpm_socket')
        );

        return match ($site->type) {
            SiteType::Php => <<<CADDY
{$hosts} {
    root * {$root}
    encode zstd gzip
    php_fastcgi unix//{$phpSock}
    file_server
}
CADDY,
            SiteType::Static => <<<CADDY
{$hosts} {
    root * {$root}
    encode zstd gzip
    file_server
}
CADDY,
            SiteType::Node => <<<CADDY
{$hosts} {
    encode zstd gzip
    reverse_proxy 127.0.0.1:{$site->app_port}
}
CADDY,
        };
    }
}
