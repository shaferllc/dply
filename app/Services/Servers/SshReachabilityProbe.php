<?php

namespace App\Services\Servers;

use App\Services\SshConnection;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SSH2;
use Throwable;

/**
 * One-shot "can we log in?" check against a host dply does not manage yet.
 *
 * {@see SshConnection} needs a persisted Server (it logs remote
 * access against one), which is exactly what the import flow doesn't have — the
 * whole point is to find out whether the key works *before* creating the row.
 */
class SshReachabilityProbe
{
    /**
     * @return array{ok: bool, message: string}
     */
    public function check(string $host, int $port, string $user, string $privateKey, int $timeout = 8): array
    {
        $host = trim($host);
        if ($host === '' || $host === '0.0.0.0') {
            return ['ok' => false, 'message' => __('No address to connect to.')];
        }

        try {
            $key = PublicKeyLoader::load($privateKey);
        } catch (Throwable) {
            return ['ok' => false, 'message' => __('That does not parse as an SSH private key.')];
        }

        try {
            $ssh = new SSH2($host, $port, $timeout);

            if (! $ssh->login($user, $key)) {
                return ['ok' => false, 'message' => __('Connected to :host, but :user was rejected — the key is not in that account\'s authorized_keys.', [
                    'host' => $host,
                    'user' => $user,
                ])];
            }

            // Prove the session actually runs commands, not just that the
            // handshake succeeded — a shell-less account authenticates fine and
            // is then useless to dply.
            $whoami = trim((string) $ssh->exec('id -un'));
            $ssh->disconnect();

            return ['ok' => true, 'message' => $whoami !== ''
                ? __('Connected as :user.', ['user' => $whoami])
                : __('Connected.')];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => __('Could not reach :host on port :port — :message', [
                'host' => $host,
                'port' => $port,
                'message' => $e->getMessage(),
            ])];
        }
    }
}
