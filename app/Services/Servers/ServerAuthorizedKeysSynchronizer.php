<?php

namespace App\Services\Servers;

use App\Models\Server;
use App\Services\SshConnection;

class ServerAuthorizedKeysSynchronizer
{
    public function sync(Server $server): string
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $ssh = new SshConnection($server);
        $existing = trim($ssh->exec('test -f ~/.ssh/authorized_keys && cat ~/.ssh/authorized_keys || true', 30));
        $lines = $existing !== '' ? array_filter(array_map('trim', preg_split('/\r\n|\n|\r/', $existing))) : [];

        foreach ($server->authorizedKeys as $row) {
            $key = trim((string) $row->public_key);
            if ($key !== '' && ! in_array($key, $lines, true)) {
                $lines[] = $key;
            }
        }

        $body = implode("\n", array_unique($lines));
        if ($body !== '') {
            $body .= "\n";
        }

        $tmp = '/tmp/dply_authorized_keys_'.bin2hex(random_bytes(6));
        $ssh->putFile($tmp, $body);
        $out = $ssh->exec(
            'mkdir -p ~/.ssh && chmod 700 ~/.ssh && mv '.escapeshellarg($tmp).' ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys 2>&1; printf "DPLY_AUTH_EXIT:%s" "$?"',
            60
        );

        if (! str_contains($out, 'DPLY_AUTH_EXIT:0')) {
            throw new \RuntimeException('Failed to update authorized_keys: '.$out);
        }

        foreach ($server->authorizedKeys as $row) {
            $row->update(['synced_at' => now()]);
        }

        return $out;
    }
}
