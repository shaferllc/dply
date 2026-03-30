<?php

namespace App\Services\Servers;

use App\Models\Server;

class ServerAuthorizedKeysSynchronizer
{
    public function __construct(
        protected ExecuteRemoteTaskOnServer $remote,
    ) {}

    public function sync(Server $server): string
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $read = $this->remote->runInlineBash(
            $server,
            'Read authorized_keys',
            'test -f ~/.ssh/authorized_keys && cat ~/.ssh/authorized_keys || true',
            30,
        );

        if (! $read->isSuccessful()) {
            throw new \RuntimeException('Failed to read authorized_keys: '.$read->getBuffer());
        }

        $existing = trim($read->getBuffer());
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
        $b64 = base64_encode($body);
        $script = "#!/bin/bash\nset -euo pipefail\n";
        $script .= 'BODY=$(echo '.escapeshellarg($b64).' | base64 -d)'."\n";
        $script .= 'TMP='.escapeshellarg($tmp)."\n";
        $script .= 'printf %s "$BODY" > "$TMP"'."\n";
        $script .= 'mkdir -p ~/.ssh && chmod 700 ~/.ssh'."\n";
        $script .= 'mv "$TMP" ~/.ssh/authorized_keys'."\n";
        $script .= 'chmod 600 ~/.ssh/authorized_keys'."\n";
        $script .= 'printf "DPLY_AUTH_EXIT:%s" "$?"'."\n";

        $out = $this->remote->runScript($server, 'Write authorized_keys', $script, 60);

        if (! $out->isSuccessful()) {
            throw new \RuntimeException('Failed to update authorized_keys: '.$out->getBuffer());
        }

        if (! str_contains($out->getBuffer(), 'DPLY_AUTH_EXIT:0')) {
            throw new \RuntimeException('Failed to update authorized_keys: '.$out->getBuffer());
        }

        foreach ($server->authorizedKeys as $row) {
            $row->update(['synced_at' => now()]);
        }

        return $out->getBuffer();
    }
}
