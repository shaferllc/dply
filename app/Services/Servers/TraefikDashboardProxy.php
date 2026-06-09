<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Jobs\Concerns\PrivilegedRemoteFileWrites;
use App\Models\Server;
use App\Services\SshConnection;

/**
 * Proxies Traefik's localhost dashboard and API over SSH (curl on the server).
 */
class TraefikDashboardProxy
{
    use PrivilegedRemoteFileWrites;

    public const LOCAL_BASE = 'http://127.0.0.1:9094';

    /**
     * Traefik ≤3.1 webui references Vite virtual boot chunks (_init, _hacks) that go:embed
     * never ships (underscore-prefixed files are excluded). Upstream always 404s them.
     */
    private const VITE_VIRTUAL_BOOT_MODULE_STUB = "export default function(){};\n";

    /**
     * @return array{status: int, body: string, content_type: string, target_url: string, admin_url: string}
     */
    public function fetch(Server $server, string $path, string $proxyPrefix): array
    {
        if (! $server->isReady() || empty($server->ssh_private_key) || blank($server->ip_address)) {
            throw new \RuntimeException('Provisioning and SSH must be ready before opening the Traefik dashboard.');
        }

        $resolved = app(TraefikAdminApiResolver::class)->resolve($server);
        $adminBase = $this->sanitizeUrlComponent(rtrim((string) $resolved['base_url'], '/'));

        $path = $this->sanitizePath($this->normalizePath($path));
        $this->guardPath($path);

        $ssh = new SshConnection($server);
        $candidates = $this->resolveTargetUrlCandidates($path, $adminBase);
        $targetUrl = $this->sanitizeUrlComponent($candidates[0]);
        $status = 502;
        $contentType = 'text/html; charset=utf-8';
        $body = '';

        foreach ($candidates as $candidateUrl) {
            $candidateUrl = $this->sanitizeUrlComponent($candidateUrl);
            $fetched = $this->executeCurlFetch($ssh, $server, $candidateUrl);
            $targetUrl = $candidateUrl;
            $status = $fetched['status'];
            $contentType = $fetched['content_type'];
            $body = $fetched['body'];

            if ($status !== 404) {
                break;
            }
        }

        if ($status === 404 && $this->isTraefikViteVirtualBootModule($path)) {
            return [
                'status' => 200,
                'body' => self::VITE_VIRTUAL_BOOT_MODULE_STUB,
                'content_type' => 'application/javascript; charset=utf-8',
                'target_url' => $targetUrl,
                'admin_url' => $adminBase,
            ];
        }

        if ($this->shouldRewriteProxiedBody($path, $contentType, $status)) {
            $body = $this->rewriteProxiedBody($path, $body, $proxyPrefix, $contentType);
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

    public function isTraefikViteVirtualBootModule(string $path): bool
    {
        return preg_match('#^assets/_(?:init|hacks)\.[a-f0-9]+\.js$#i', $path) === 1
            || preg_match('#^assets/chunks/(?:init|hacks)\.[a-f0-9]+\.js$#i', $path) === 1;
    }

    /**
     * @return array{status: int, body: string, content_type: string}
     */
    private function parseCurlOutput(string $output, string $targetUrl, int $exit): array
    {
        if (preg_match('/\ADPLY_CURL_ERROR:(.+)\z/s', $output, $curlMatch) === 1) {
            $curlError = trim((string) $curlMatch[1]);

            throw new \RuntimeException($this->formatConnectionFailure($curlError, $targetUrl));
        }

        if (preg_match('/\ADPLY_HTTP_STATUS:(\d+)\r?\nDPLY_CONTENT_TYPE:([^\r\n]*)\r?\n/', $output, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            if ($exit !== 0) {
                throw new \RuntimeException($output !== '' ? $output : 'Traefik dashboard request failed.');
            }

            throw new \RuntimeException('Traefik dashboard request returned an invalid response envelope.');
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
        $path = trim(str_replace('\\', '/', $path), '/');

        return $path;
    }

    public function guardPath(string $path): void
    {
        if (str_contains($path, '..') || str_contains($path, "\0")) {
            throw new \InvalidArgumentException('Invalid dashboard path.');
        }

        if ($path !== '' && ! preg_match('/^[a-zA-Z0-9_.\/-]+$/', $path)) {
            throw new \InvalidArgumentException('Invalid dashboard path.');
        }
    }

    private function sanitizePath(string $path): string
    {
        return str_replace("\0", '', $path);
    }

    private function sanitizeUrlComponent(string $url): string
    {
        $url = str_replace("\0", '', $url);

        return preg_replace('/[\x00-\x1F\x7F]/', '', $url) ?? $url;
    }

    public function resolveTargetUrl(string $path, string $adminBase = self::LOCAL_BASE): string
    {
        return $this->resolveTargetUrlCandidates($path, $adminBase)[0];
    }

    /**
     * Traefik may serve hashed dashboard bundles from /dashboard/assets/* or /assets/* on the API port.
     *
     * @return list<string>
     */
    public function resolveTargetUrlCandidates(string $path, string $adminBase = self::LOCAL_BASE): array
    {
        $base = rtrim($adminBase, '/');

        if ($path === '' || $path === 'dashboard') {
            return [$base.'/dashboard/'];
        }

        if ($path === 'api' || str_starts_with($path, 'api/')) {
            $suffix = $path === 'api' ? '' : substr($path, 4);
            $url = $base.'/api';
            if ($suffix !== '') {
                $url .= '/'.ltrim($suffix, '/');
            }

            return [$url];
        }

        if (preg_match('#^assets/chunks/(.+)$#', $path, $chunkMatch) === 1) {
            $file = $chunkMatch[1];

            return array_values(array_unique([
                $base.'/dashboard/assets/_'.$file,
                $base.'/dashboard/assets/chunks/'.$file,
                $base.'/dashboard/assets/'.$file,
                $base.'/assets/_'.$file,
                $base.'/assets/chunks/'.$file,
            ]));
        }

        $candidates = [$base.'/dashboard/'.ltrim($path, '/')];

        if (str_starts_with($path, 'assets/')) {
            $candidates[] = $base.'/'.ltrim($path, '/');
        }

        return array_values(array_unique($candidates));
    }

    private function formatConnectionFailure(string $curlError, string $adminBase): string
    {
        $hint = __('Could not reach the Traefik API at :url.', ['url' => $adminBase]);
        if (str_contains($curlError, 'Connection refused') || str_contains($curlError, 'Failed to connect')) {
            $hint .= ' '.__('Use Repair API on :port on the Traefik Overview tab, or enable API dashboard + the `traefik` entry point (127.0.0.1:9094) in static config and restart Traefik.', ['port' => '9094']);
        }

        return trim($hint.' '.$curlError);
    }

    private function shouldRewriteProxiedBody(string $path, string $contentType, int $status): bool
    {
        if ($status < 200 || $status >= 300) {
            return false;
        }

        if ($path === '' || $path === 'dashboard' || $this->isTraefikDashboardEntryScript($path)) {
            return true;
        }

        if (str_ends_with($path, '.css')) {
            return true;
        }

        $type = strtolower(explode(';', trim($contentType))[0]);

        return in_array($type, ['text/html', 'text/css'], true);
    }

    private function isTraefikDashboardEntryScript(string $path): bool
    {
        $basename = basename($path);

        return preg_match('/^(?:index|api)(\.[a-f0-9]+)?\.js$/', $basename) === 1;
    }

    private function rewriteProxiedBody(string $path, string $body, string $proxyPrefix, string $contentType): string
    {
        if ($path === '' || $path === 'dashboard' || str_contains(strtolower($contentType), 'text/html')) {
            return $this->rewriteHtmlBody($body, $proxyPrefix);
        }

        if (str_ends_with($path, '.css') || str_contains(strtolower($contentType), 'text/css')) {
            return $this->rewriteStylesheetBody($body, $proxyPrefix);
        }

        if (preg_match('/^api(\.[a-f0-9]+)?\.js$/', basename($path)) === 1) {
            return $this->rewriteApiScriptBody($body, $proxyPrefix);
        }

        if (preg_match('/^index(\.[a-f0-9]+)?\.js$/', basename($path)) === 1) {
            return $this->rewriteIndexScriptBody($body, $proxyPrefix);
        }

        return $body;
    }

    private function rewriteHtmlBody(string $body, string $proxyPrefix): string
    {
        $body = $this->rewriteBody($body, $proxyPrefix);
        $prefix = rtrim($proxyPrefix, '/');
        $apiBase = $prefix.'/api';
        $baseHref = $prefix.'/';

        $shim = '<script>(function(){var apiBase='.json_encode($apiBase, JSON_UNESCAPED_SLASHES).';'
            .'function rw(u){if(typeof u!=="string")return u;'
            .'try{var p=new URL(u,window.location.href);'
            .'if(p.origin===window.location.origin&&p.pathname.indexOf("/api/")===0){return apiBase+p.pathname.slice(4)+p.search;}'
            .'if(p.origin===window.location.origin&&p.pathname==="/api"){return apiBase+p.search;}}catch(e){}'
            .'if(u.indexOf("/api/")===0)return apiBase+u.slice(4);'
            .'if(u==="/api")return apiBase;return u;}'
            .'var f=window.fetch;if(typeof f==="function"){window.fetch=function(i,n){'
            .'if(typeof i==="string")i=rw(i);'
            .'else if(i&&typeof i.url==="string")i=new Request(rw(i.url),i);'
            .'return f.call(this,i,n);};}'
            .'var o=XMLHttpRequest.prototype.open;'
            .'XMLHttpRequest.prototype.open=function(){arguments[1]=rw(arguments[1]);return o.apply(this,arguments);};'
            .'})();</script>';

        if (! preg_match('/<base\s/i', $body)) {
            $injected = '<base href="'.htmlspecialchars($baseHref, ENT_QUOTES).'" />';
            $replaced = preg_replace('/<head([^>]*)>/i', '<head$1>'.$injected.$shim, $body, 1);
            if (is_string($replaced)) {
                $body = $replaced;
            } else {
                $body = $shim.$body;
            }
        } else {
            $body = preg_replace('/<head([^>]*)>/i', '<head$1>'.$shim, $body, 1) ?? ($shim.$body);
        }

        return $body;
    }

    private function rewriteStylesheetBody(string $body, string $proxyPrefix): string
    {
        $prefix = rtrim($proxyPrefix, '/');
        $assetPrefix = $prefix.'/assets/';
        $apiPrefix = $prefix.'/api/';

        $body = preg_replace('#url\(\s*"/dashboard/#', 'url("'.$prefix.'/', $body) ?? $body;
        $body = preg_replace("#url\\(\\s*'/dashboard/#", "url('{$prefix}/", $body) ?? $body;
        $body = preg_replace('#url\(\s*"/assets/#', 'url("'.$assetPrefix, $body) ?? $body;
        $body = preg_replace("#url\\(\\s*'/assets/#", "url('{$assetPrefix}", $body) ?? $body;
        $body = preg_replace('#url\(\s*/assets/#', 'url('.$assetPrefix, $body) ?? $body;
        $body = preg_replace('#url\(\s*"/api/#', 'url("'.$apiPrefix, $body) ?? $body;

        return $body;
    }

    private function rewriteApiScriptBody(string $body, string $proxyPrefix): string
    {
        $apiBase = rtrim($proxyPrefix, '/').'/api';

        $body = str_replace([
            'baseURL:"/api"',
            "baseURL:'/api'",
            'baseURL: "/api"',
            "baseURL: '/api'",
            '"/api/',
            "'/api/",
        ], [
            'baseURL:"'.$apiBase.'"',
            "baseURL:'".$apiBase."'",
            'baseURL: "'.$apiBase.'"',
            "baseURL: '".$apiBase."'",
            '"'.$apiBase.'/',
            "'".$apiBase.'/',
        ], $body);

        return $body;
    }

    /**
     * Boot bundle only — chunk modules (_*.js) must pass through unchanged.
     */
    private function rewriteIndexScriptBody(string $body, string $proxyPrefix): string
    {
        $prefix = rtrim($proxyPrefix, '/');
        $assetPrefix = $prefix.'/assets/';
        $apiPrefix = $prefix.'/api/';

        $body = str_replace([
            'import("/assets/',
            "import('/assets/",
            'import("/api/',
            "import('/api/",
        ], [
            "import(\"{$assetPrefix}",
            "import('{$assetPrefix}",
            "import(\"{$apiPrefix}",
            "import('{$apiPrefix}",
        ], $body);

        $body = preg_replace('#([="(\s])"/assets/#', '$1"'.$assetPrefix, $body) ?? $body;
        $body = preg_replace("#([=\"(\\s])'/assets/#", "$1'{$assetPrefix}", $body) ?? $body;
        $body = preg_replace('#([="(\s])"/api/#', '$1"'.$apiPrefix, $body) ?? $body;
        $body = preg_replace("#([=\"(\\s])'/api/#", "$1'{$apiPrefix}", $body) ?? $body;

        return $body;
    }

    private function rewriteBody(string $body, string $proxyPrefix): string
    {
        $prefix = rtrim($proxyPrefix, '/');
        $apiPrefix = $prefix.'/api/';
        $assetPrefix = $prefix.'/assets/';

        // Axios / Quasar API client (no trailing slash on baseURL → host-root /api/*).
        $body = str_replace([
            'baseURL:"/api"',
            "baseURL:'/api'",
            'baseURL: "/api"',
            "baseURL: '/api'",
        ], [
            "baseURL:\"{$prefix}/api\"",
            "baseURL:'{$prefix}/api'",
            "baseURL: \"{$prefix}/api\"",
            "baseURL: '{$prefix}/api'",
        ], $body);

        // /dashboard/* on Traefik becomes the proxy mount (prefix is already the dashboard URL).
        $body = str_replace([
            'href="/dashboard/',
            "href='/dashboard/",
            'src="/dashboard/',
            "src='/dashboard/",
            '"/dashboard/',
            "'/dashboard/",
            'fetch("/dashboard/',
            "fetch('/dashboard/",
            'import("/dashboard/',
            "import('/dashboard/",
            'url("/dashboard/',
            "url('/dashboard/",
            'url(/dashboard/',
        ], [
            "href=\"{$prefix}/",
            "href='{$prefix}/",
            "src=\"{$prefix}/",
            "src='{$prefix}/",
            "\"{$prefix}/",
            "'{$prefix}/",
            "fetch(\"{$prefix}/",
            "fetch('{$prefix}/",
            "import(\"{$prefix}/",
            "import('{$prefix}/",
            "url(\"{$prefix}/",
            "url('{$prefix}/",
            "url({$prefix}/",
        ], $body);

        // Root-absolute /api/* (must run after /dashboard/ so we do not corrupt .../dashboard/api/).
        $body = str_replace([
            'href="/api/',
            "href='/api/",
            'src="/api/',
            "src='/api/",
            '"/api/',
            "'/api/",
            'fetch("/api/',
            "fetch('/api/",
        ], [
            "href=\"{$apiPrefix}",
            "href='{$apiPrefix}",
            "src=\"{$apiPrefix}",
            "src='{$apiPrefix}",
            "\"{$apiPrefix}",
            "'{$apiPrefix}",
            "fetch(\"{$apiPrefix}",
            "fetch('{$apiPrefix}",
        ], $body);
        $body = str_replace('\'/api/', '\''.$prefix.'/api/', $body);

        // Root-absolute /assets/* only (never blanket-replace "/assets/" after /dashboard/ rewrite).
        $body = preg_replace('#([="(\s])"/assets/#', '$1"'.$assetPrefix, $body) ?? $body;
        $body = preg_replace("#([=\"(\\s])'/assets/#", "$1'{$assetPrefix}", $body) ?? $body;
        $body = str_replace('import("/assets/', "import(\"{$assetPrefix}", $body);
        $body = str_replace("import('/assets/", "import('{$assetPrefix}", $body);
        $body = preg_replace('#url\(\s*"/assets/#', "url(\"{$assetPrefix}", $body) ?? $body;
        $body = preg_replace("#url\\(\\s*'/assets/#", "url('{$assetPrefix}", $body) ?? $body;
        $body = preg_replace('#url\(\s*/assets/#', "url({$assetPrefix}", $body) ?? $body;

        // Relative assets/ (resolves to /traefik/assets/ when dashboard URL has no trailing slash).
        foreach (['href=', 'src=', 'content='] as $attr) {
            $body = str_replace("{$attr}\"assets/", "{$attr}\"{$assetPrefix}", $body);
            $body = str_replace("{$attr}'assets/", "{$attr}'{$assetPrefix}", $body);
        }

        return $body;
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
