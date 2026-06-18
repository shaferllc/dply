<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Jobs\Concerns\PrivilegedRemoteFileWrites;
use App\Models\Server;
use App\Services\SshConnection;
use App\Support\Servers\CaddyAdminUrl;

/**
 * Read-only proxy to Caddy's localhost admin API over SSH.
 * Never binds admin to the public network — dply curls 127.0.0.1 from the server.
 */
class CaddyAdminApiProxy
{
    use PrivilegedRemoteFileWrites;

    /** @var list<string> */
    public const ALLOWED_PATHS = [
        'config',
        'reverse_proxy/upstreams',
        'pki/ca/local',
        'pki/ca',
        'id',
        'metrics',
    ];

    /**
     * @return array{status: int, body: string, content_type: string, admin_url: string}
     */
    /** @return array<string, mixed> */
    public function fetch(Server $server, string $path): array
    {
        if (! $server->isReady() || empty($server->ssh_private_key) || blank($server->ip_address)) {
            throw new \RuntimeException('Provisioning and SSH must be ready before using the Caddy admin API.');
        }

        $path = $this->normalizePath($path);
        $this->guardPath($path);

        $adminBase = $this->resolveAdminBaseUrl($server);
        if ($adminBase === null) {
            throw new \RuntimeException('Caddy admin API is disabled (`admin off` in the global Caddyfile block).');
        }

        $targetUrl = rtrim($adminBase, '/').'/'.$path;
        if ($path === 'config' || $path === 'pki/ca' || $path === 'id') {
            $targetUrl .= '/';
        }

        $ssh = new SshConnection($server);
        $script = $this->buildCurlScript($targetUrl);
        $output = $ssh->exec($this->privilegedCommand($server, $script), 30);
        $exit = $ssh->lastExecExitCode() ?? 1;

        $status = 502;
        $body = trim((string) $output);
        if (preg_match('/^DPLY_HTTP_STATUS:(\d+)\s*$/m', $body, $matches) === 1) {
            $status = (int) $matches[1];
            $body = trim((string) preg_replace('/^DPLY_HTTP_STATUS:\d+\s*$/m', '', $body));
        } elseif ($exit !== 0) {
            throw new \RuntimeException($body !== '' ? $body : 'Caddy admin API request failed.');
        }

        return [
            'status' => $status,
            'body' => $body,
            'content_type' => $path === 'metrics' ? 'text/plain; charset=utf-8' : 'application/json; charset=utf-8',
            'admin_url' => $targetUrl,
        ];
    }

    public function normalizePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');

        return $path === '' ? 'config' : $path;
    }

    public function guardPath(string $path): void
    {
        if ($path === '' || str_contains($path, '..')) {
            throw new \InvalidArgumentException('Invalid admin API path.');
        }

        if (! preg_match('/^[a-zA-Z0-9_.\/-]+$/', $path)) {
            throw new \InvalidArgumentException('Invalid admin API path.');
        }

        foreach (self::ALLOWED_PATHS as $allowed) {
            if ($path === $allowed || str_starts_with($path, $allowed.'/')) {
                return;
            }
        }

        throw new \InvalidArgumentException('That admin API path is not exposed through Dply.');
    }

    private function resolveAdminBaseUrl(Server $server): ?string
    {
        $read = app(CaddyGlobalOptionsConfig::class)->read($server);
        $adminListen = trim((string) ($read['values']['admin'] ?? 'localhost:2019'));

        return CaddyAdminUrl::fromListenDirective($adminListen !== '' ? $adminListen : 'localhost:2019');
    }

    private function buildCurlScript(string $targetUrl): string
    {
        $quotedUrl = escapeshellarg($targetUrl);

        return <<<BASH
set +e
body=\$(curl -sS --max-time 10 -w '\nDPLY_HTTP_STATUS:%{http_code}' {$quotedUrl} 2>&1)
code=\$?
if [ \$code -ne 0 ]; then
  echo "\$body" >&2
  exit \$code
fi
printf '%s' "\$body"
BASH;
    }
}
