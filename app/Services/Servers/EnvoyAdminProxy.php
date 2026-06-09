<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Jobs\Concerns\PrivilegedRemoteFileWrites;
use App\Models\Server;
use App\Services\SshConnection;
use App\Support\Servers\EnvoyAdminScript;

/**
 * Proxies Envoy's localhost admin interface (:9901) over SSH.
 */
class EnvoyAdminProxy
{
    use PrivilegedRemoteFileWrites;

    public const LOCAL_BASE = EnvoyAdminScript::ADMIN_BASE;

    /**
     * @return array{status: int, body: string, content_type: string, target_url: string, admin_url: string}
     */
    public function fetch(Server $server, string $path, string $proxyPrefix): array
    {
        if (! $server->isReady() || empty($server->ssh_private_key) || blank($server->ip_address)) {
            throw new \RuntimeException('Provisioning and SSH must be ready before opening the Envoy admin UI.');
        }

        $path = $this->sanitizePath($this->normalizePath($path));
        $this->guardPath($path);

        $adminBase = rtrim(self::LOCAL_BASE, '/');
        $targetUrl = $path === '' ? $adminBase.'/' : $adminBase.'/'.ltrim($path, '/');

        $ssh = new SshConnection($server);
        $fetched = $this->executeCurlFetch($ssh, $server, $targetUrl);
        $status = $fetched['status'];
        $contentType = $fetched['content_type'];
        $body = $fetched['body'];

        if ($this->shouldRewriteProxiedBody($path, $contentType, $status)) {
            $body = $this->rewriteProxiedBody($body, $proxyPrefix, $contentType);
        }

        return [
            'status' => $status,
            'body' => $body,
            'content_type' => $contentType,
            'target_url' => $targetUrl,
            'admin_url' => $adminBase,
        ];
    }

    /**
     * @return array{status: int, body: string, content_type: string}
     */
    private function executeCurlFetch(SshConnection $ssh, Server $server, string $targetUrl): array
    {
        $script = $this->buildCurlScript($targetUrl);
        $output = $ssh->exec($this->privilegedCommand($server, $script), 45);
        $exit = $ssh->lastExecExitCode() ?? 1;

        return $this->parseCurlOutput(trim((string) $output), $targetUrl, $exit);
    }

    /**
     * @return array{status: int, body: string, content_type: string}
     */
    private function parseCurlOutput(string $output, string $targetUrl, int $exit): array
    {
        if (preg_match('/\ADPLY_CURL_ERROR:(.+)\z/s', $output, $curlMatch) === 1) {
            throw new \RuntimeException($this->formatConnectionFailure(trim((string) $curlMatch[1]), $targetUrl));
        }

        if (preg_match('/\ADPLY_HTTP_STATUS:(\d+)\r?\nDPLY_CONTENT_TYPE:([^\r\n]*)\r?\n/', $output, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            if ($exit !== 0) {
                throw new \RuntimeException($output !== '' ? $output : 'Envoy admin request failed.');
            }

            throw new \RuntimeException('Envoy admin request returned an invalid response envelope.');
        }

        $status = (int) $matches[1][0];
        $contentType = trim((string) $matches[2][0]);
        $body = substr($output, $matches[0][1] + strlen($matches[0][0]));

        if (str_starts_with($body, 'DPLY_BODY_B64:')) {
            $decoded = base64_decode(substr($body, strlen('DPLY_BODY_B64:')), true);
            $body = $decoded !== false ? $decoded : '';
        }

        if ($contentType === '') {
            $contentType = 'application/octet-stream';
        }

        return [
            'status' => $status,
            'body' => $body,
            'content_type' => $contentType,
        ];
    }

    public function normalizePath(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }

    public function guardPath(string $path): void
    {
        if (str_contains($path, '..') || str_contains($path, "\0")) {
            throw new \InvalidArgumentException('Invalid admin path.');
        }

        if ($path !== '' && ! preg_match('/^[a-zA-Z0-9_.\/=?&%-]+$/', $path)) {
            throw new \InvalidArgumentException('Invalid admin path.');
        }
    }

    private function sanitizePath(string $path): string
    {
        return str_replace("\0", '', $path);
    }

    private function formatConnectionFailure(string $curlError, string $adminBase): string
    {
        $hint = __('Could not reach the Envoy admin interface at :url.', ['url' => $adminBase]);
        if (str_contains($curlError, 'Connection refused') || str_contains($curlError, 'Failed to connect')) {
            $hint .= ' '.__('Start Envoy from Overview or use Repair admin on :port, then try again.', ['port' => '9901']);
        }

        return trim($hint.' '.$curlError);
    }

    private function shouldRewriteProxiedBody(string $path, string $contentType, int $status): bool
    {
        if ($status < 200 || $status >= 300) {
            return false;
        }

        $type = strtolower(explode(';', trim($contentType))[0]);

        return in_array($type, ['text/html', 'text/css'], true);
    }

    private function rewriteProxiedBody(string $body, string $proxyPrefix, string $contentType): string
    {
        if (str_contains(strtolower($contentType), 'text/html')) {
            return $this->rewriteHtmlBody($body, $proxyPrefix);
        }

        return $this->rewriteStylesheetBody($body, $proxyPrefix);
    }

    private function rewriteHtmlBody(string $body, string $proxyPrefix): string
    {
        $prefix = rtrim($proxyPrefix, '/');
        $shim = '<script>(function(){var base='.json_encode($prefix, JSON_UNESCAPED_SLASHES).';'
            .'function rw(u){if(typeof u!=="string")return u;'
            .'if(u.charAt(0)==="/")return base+u;'
            .'try{var p=new URL(u,window.location.href);'
            .'if(p.origin===window.location.origin&&p.pathname.indexOf("/")===0){return base+p.pathname+p.search;}}catch(e){}'
            .'return u;}'
            .'var f=window.fetch;if(typeof f==="function"){window.fetch=function(i,n){'
            .'if(typeof i==="string")i=rw(i);'
            .'else if(i&&typeof i.url==="string")i=new Request(rw(i.url),i);'
            .'return f.call(this,i,n);};}'
            .'var o=XMLHttpRequest.prototype.open;'
            .'XMLHttpRequest.prototype.open=function(){arguments[1]=rw(arguments[1]);return o.apply(this,arguments);};'
            .'})();</script>';

        $body = $this->rewriteRootAbsoluteUrls($body, $prefix);

        if (! preg_match('/<base\s/i', $body)) {
            $injected = '<base href="'.htmlspecialchars($prefix.'/', ENT_QUOTES).'" />';
            $replaced = preg_replace('/<head([^>]*)>/i', '<head$1>'.$injected.$shim, $body, 1);
            $body = is_string($replaced) ? $replaced : ($shim.$body);
        } else {
            $body = preg_replace('/<head([^>]*)>/i', '<head$1>'.$shim, $body, 1) ?? ($shim.$body);
        }

        return $body;
    }

    private function rewriteStylesheetBody(string $body, string $proxyPrefix): string
    {
        return $this->rewriteRootAbsoluteUrls($body, rtrim($proxyPrefix, '/'));
    }

    private function rewriteRootAbsoluteUrls(string $body, string $prefix): string
    {
        $replacements = [
            'href="/' => 'href="'.$prefix.'/',
            "href='/" => "href='".$prefix.'/',
            'src="/' => 'src="'.$prefix.'/',
            "src='/" => "src='".$prefix.'/',
            'action="/' => 'action="'.$prefix.'/',
            "action='/" => "action='".$prefix.'/',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $body);
    }

    private function buildCurlScript(string $targetUrl): string
    {
        $quotedUrl = escapeshellarg($targetUrl);

        return <<<BASH
set +e
err_file=\$(mktemp)
body_file=\$(mktemp)
hdr_file=\$(mktemp)
http_code=\$(curl -sS -L --max-time 20 -o "\$body_file" -D "\$hdr_file" -w '%{http_code}' -- {$quotedUrl} 2>"\$err_file")
code=\$?
if [ \$code -ne 0 ]; then
  err=\$(tr -d '\0' < "\$err_file" | tail -n 5)
  rm -f "\$err_file" "\$body_file" "\$hdr_file"
  printf 'DPLY_CURL_ERROR:%s' "\$err"
  exit \$code
fi
ctype=\$(grep -i '^content-type:' "\$hdr_file" | tail -n 1 | sed 's/^[^:]*:[[:space:]]*//' | tr -d '\r')
rm -f "\$err_file" "\$hdr_file"
printf 'DPLY_HTTP_STATUS:%s\n' "\$http_code"
printf 'DPLY_CONTENT_TYPE:%s\n' "\$ctype"
printf 'DPLY_BODY_B64:'
base64 < "\$body_file" | tr -d '\n'
rm -f "\$body_file"
BASH;
    }
}
