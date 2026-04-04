<?php

namespace App\Services\Sites\Clone;

use App\Models\Server;
use App\Services\Servers\ServerSshConnectionRunner;
use App\Services\SshConnection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Copies a site repository tree between servers (or on the same server) using rsync or a tar pipe over SSH.
 */
final class RepositoryTreeCopier
{
    public function __construct(
        private readonly ServerSshConnectionRunner $sshRunner,
    ) {}

    /**
     * @throws \RuntimeException
     */
    public function copyTree(Server $sourceServer, string $sourcePath, Server $destServer, string $destPath): void
    {
        $src = rtrim($sourcePath, '/');
        $dst = rtrim($destPath, '/');
        if ($src === '' || $dst === '') {
            throw new \RuntimeException(__('Source or destination path is empty.'));
        }

        if ($sourceServer->id === $destServer->id) {
            $this->copySameServer($sourceServer, $src, $dst);

            return;
        }

        $this->copyCrossServer($sourceServer, $src, $destServer, $dst);
    }

    private function copySameServer(Server $server, string $src, string $dst): void
    {
        $this->sshRunner->run($server, function (SshConnection $ssh) use ($src, $dst): void {
            $bash = sprintf(
                'mkdir -p %s && rsync -a %s/ %s/',
                escapeshellarg(dirname($dst)),
                escapeshellarg($src),
                escapeshellarg($dst)
            );
            $privileged = $ssh->effectiveUsername() === 'root'
                ? $bash
                : 'sudo -n '.$bash;

            $out = $ssh->exec(sprintf('(%s) 2>&1; printf "\nDPLY_EXIT:%%s" "$?"', $privileged), 3600);
            if (! preg_match('/DPLY_EXIT:0\s*$/', $out)) {
                throw new \RuntimeException(Str::limit(trim($out), 2000));
            }
        });
    }

    private function copyCrossServer(Server $sourceServer, string $src, Server $destServer, string $dst): void
    {
        $srcKey = $this->writeTempKey($sourceServer);
        $dstKey = $this->writeTempKey($destServer);

        try {
            $srcParent = dirname($src);
            $srcBase = basename($src);
            $dstParent = dirname($dst);
            $dstBase = basename($dst);

            $srcUser = trim((string) $sourceServer->ssh_user) ?: 'root';
            $dstUser = trim((string) $destServer->ssh_user) ?: 'root';
            $srcHost = (string) $sourceServer->ip_address;
            $dstHost = (string) $destServer->ip_address;

            $sshOpts = '-o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=/dev/null';

            $tarRemote = sprintf('sudo tar czf - -C %s %s', escapeshellarg($srcParent), escapeshellarg($srcBase));

            $afterExtract = sprintf(
                'sudo mkdir -p %s && sudo tar xzf - -C %s',
                escapeshellarg($dstParent),
                escapeshellarg($dstParent)
            );
            if ($srcBase !== $dstBase) {
                $afterExtract .= sprintf(
                    ' && sudo rm -rf %s 2>/dev/null || true && sudo mv %s %s',
                    escapeshellarg($dst),
                    escapeshellarg($dstParent.'/'.$srcBase),
                    escapeshellarg($dst)
                );
            }

            $bash = sprintf(
                '%s | %s',
                sprintf(
                    'ssh %s -i %s %s@%s %s',
                    $sshOpts,
                    escapeshellarg($srcKey),
                    escapeshellarg($srcUser),
                    escapeshellarg($srcHost),
                    escapeshellarg($tarRemote)
                ),
                sprintf(
                    'ssh %s -i %s %s@%s %s',
                    $sshOpts,
                    escapeshellarg($dstKey),
                    escapeshellarg($dstUser),
                    escapeshellarg($dstHost),
                    escapeshellarg($afterExtract)
                )
            );

            $process = Process::fromShellCommandline($bash);
            $process->setTimeout(3600);
            $process->run();

            if (! $process->isSuccessful()) {
                $err = trim($process->getErrorOutput().$process->getOutput());

                throw new \RuntimeException($err !== '' ? $err : __('Cross-server file copy failed.'));
            }
        } finally {
            @unlink($srcKey);
            @unlink($dstKey);
        }
    }

    private function writeTempKey(Server $server): string
    {
        $key = $server->operationalSshPrivateKey() ?? $server->recoverySshPrivateKey();
        if (! is_string($key) || trim($key) === '') {
            throw new \RuntimeException(__('Server is missing an SSH private key for file copy.'));
        }

        $path = tempnam(sys_get_temp_dir(), 'dply_clone_key_');
        if ($path === false) {
            throw new \RuntimeException(__('Could not create a temporary key file.'));
        }

        File::put($path, $key);
        chmod($path, 0600);

        return $path;
    }
}
