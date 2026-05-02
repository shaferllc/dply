<?php

namespace App\Services\Sites;

use App\Enums\SiteRedirectKind;
use App\Enums\SiteType;
use App\Models\Site;
use App\Support\SiteRedirectConfigSupport;

class CaddySiteConfigBuilder
{
    public function build(Site $site, ?int $listenPort = null): string
    {
        $site->loadMissing(['domains', 'domainAliases', 'tenantDomains', 'redirects']);

        $hostnames = collect($listenPort === null ? $site->webserverHostnames() : [])
            ->filter()
            ->unique()
            ->values();
        if ($hostnames->isEmpty()) {
            if ($listenPort === null) {
                throw new \InvalidArgumentException('Add at least one domain before installing Caddy.');
            }
        }

        $hosts = $listenPort === null ? $hostnames->implode(', ') : ':'.$listenPort;
        $basename = $site->webserverConfigBasename();

        if ($site->isSuspended()) {
            return $this->suspendedSiteBlock($hosts, $basename, $site);
        }

        $root = $site->effectiveDocumentRoot();
        $phpSock = str_replace(
            '{version}',
            $site->php_version ?? '8.3',
            config('sites.php_fpm_socket')
        );
        $redirectLines = $this->redirectLines($site);

        if ($site->type === SiteType::Php && $site->octane_port) {
            $port = (int) $site->octane_port;
            $reverb = $this->reverbHandleDirective($site);

            return <<<CADDY
{$hosts} {
{$redirectLines}{$reverb}    encode zstd gzip
    log {
        output file /var/log/caddy/{$basename}-access.log
    }
    reverse_proxy 127.0.0.1:{$port}
}
CADDY;
        }

        $reverbPhp = $site->type === SiteType::Php ? $this->reverbHandleDirective($site) : '';

        return match ($site->type) {
            SiteType::Php => <<<CADDY
{$hosts} {
{$redirectLines}{$reverbPhp}    root * {$root}
    encode zstd gzip
    log {
        output file /var/log/caddy/{$basename}-access.log
    }
    php_fastcgi unix//{$phpSock}
    file_server
}
CADDY,
            SiteType::Static => <<<CADDY
{$hosts} {
{$redirectLines}    root * {$root}
    encode zstd gzip
    log {
        output file /var/log/caddy/{$basename}-access.log
    }
    file_server
}
CADDY,
            SiteType::Node => <<<CADDY
{$hosts} {
{$redirectLines}    encode zstd gzip
    log {
        output file /var/log/caddy/{$basename}-access.log
    }
    reverse_proxy 127.0.0.1:{$site->app_port}
}
CADDY,
        };
    }

    private function suspendedSiteBlock(string $hosts, string $basename, Site $site): string
    {
        $root = $site->suspendedStaticRoot();

        return <<<CADDY
{$hosts} {
    root * {$root}
    encode zstd gzip
    log {
        output file /var/log/caddy/{$basename}-access.log
    }
    file_server
}
CADDY;
    }

    private function reverbHandleDirective(Site $site): string
    {
        if (! $site->shouldProxyReverbInWebserver()) {
            return '';
        }

        $path = $site->reverbWebSocketPath();
        $port = $site->reverbLocalPort();

        return "    handle {$path}* {\n        reverse_proxy 127.0.0.1:{$port}\n    }\n";
    }

    private function redirectLines(Site $site): string
    {
        $lines = [];
        $matcherIndex = 0;
        foreach ($site->redirects->sortBy('sort_order') as $redirect) {
            $from = SiteRedirectConfigSupport::sanitizeFromPath((string) $redirect->from_path);
            if ($from === '') {
                continue;
            }
            $kind = $redirect->kind instanceof SiteRedirectKind ? $redirect->kind : SiteRedirectKind::Http;
            if ($kind === SiteRedirectKind::InternalRewrite) {
                $to = SiteRedirectConfigSupport::sanitizeInternalTarget((string) $redirect->to_url);
                if ($to === '') {
                    continue;
                }
                $lines[] = "    rewrite {$from} {$to}";

                continue;
            }
            $code = (int) $redirect->status_code;
            if (! in_array($code, SiteRedirectConfigSupport::allowedHttpRedirectStatusCodes(), true)) {
                continue;
            }
            $to = trim((string) $redirect->to_url);
            if ($to === '') {
                continue;
            }
            $headers = SiteRedirectConfigSupport::normalizeResponseHeaders($redirect->response_headers ?? null);
            if ($headers !== []) {
                $name = 'dply_redir_'.$matcherIndex;
                $matcherIndex++;
                $block = "    @{$name} path {$from}\n    handle @{$name} {\n";
                foreach ($headers as $h) {
                    $hv = SiteRedirectConfigSupport::escapeCaddyHeaderValue($h['value']);
                    $block .= "        header {$h['name']} \"{$hv}\"\n";
                }
                $block .= "        redir {$to} {$code}\n    }";
                $lines[] = $block;
            } else {
                $lines[] = "    redir {$from} {$to} {$code}";
            }
        }

        if ($lines === []) {
            return '';
        }

        return implode("\n", $lines)."\n";
    }
}
