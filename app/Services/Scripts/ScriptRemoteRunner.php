<?php

namespace App\Services\Scripts;

use App\Models\Script;
use App\Models\Server;
use App\Services\SshConnection;
use Illuminate\Support\Str;
use Throwable;

class ScriptRemoteRunner
{
    /**
     * Upload and execute script body on the server over SSH.
     *
     * @return array{ok: bool, output: string, error: string|null}
     */
    public function run(Script $script, Server $server, int $timeoutSeconds = 600): array
    {
        $tmp = '/tmp/dply-script-'.Str::lower(Str::random(20)).'.sh';

        try {
            $ssh = new SshConnection($server);
            $ssh->putFile($tmp, $script->content);

            $runner = new SshConnection($server);
            if (! $runner->connect()) {
                return ['ok' => false, 'output' => '', 'error' => __('Could not connect via SSH.')];
            }

            $runner->exec('chmod 700 '.escapeshellarg($tmp), 30);

            $runAs = trim((string) $script->run_as_user);
            $sshUser = (string) $server->ssh_user;

            if ($runAs === '') {
                $cmd = 'bash '.escapeshellarg($tmp).' 2>&1';
            } elseif ($runAs === $sshUser) {
                $cmd = 'bash '.escapeshellarg($tmp).' 2>&1';
            } else {
                $cmd = 'sudo -n -u '.escapeshellarg($runAs).' bash '.escapeshellarg($tmp).' 2>&1';
            }

            $output = $runner->exec($cmd, $timeoutSeconds);

            $runner->exec('rm -f '.escapeshellarg($tmp), 30);

            return ['ok' => true, 'output' => $output, 'error' => null];
        } catch (Throwable $e) {
            return ['ok' => false, 'output' => '', 'error' => $e->getMessage()];
        }
    }
}
