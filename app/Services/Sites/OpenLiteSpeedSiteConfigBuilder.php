<?php

namespace App\Services\Sites;

use App\Enums\SiteRedirectKind;
use App\Enums\SiteType;
use App\Models\Site;
use App\Support\SiteRedirectConfigSupport;
use Illuminate\Support\Collection;

class OpenLiteSpeedSiteConfigBuilder
{
    public function build(Site $site): string
    {
        $site->loadMissing(['domains', 'domainAliases', 'tenantDomains', 'redirects']);

        $hostnames = collect($site->webserverHostnames())
            ->filter()
            ->unique()
            ->values();
        if ($hostnames->isEmpty()) {
            throw new \InvalidArgumentException('Add at least one domain before installing OpenLiteSpeed.');
        }

        $vhostRoot = rtrim($site->effectiveRepositoryPath(), '/');

        if ($site->isSuspended()) {
            return $this->buildSuspendedVhost($site, $hostnames, $vhostRoot);
        }

        $root = $site->effectiveDocumentRoot();
        $phpBinary = '/usr/local/lsws/lsphp'.str_replace('.', '', (string) ($site->php_version ?? '83')).'/bin/lsphp';

        if ($site->type === SiteType::Php && $site->octane_port) {
            return $this->buildPhpOctaneProxy($site, $hostnames, $vhostRoot);
        }

        return match ($site->type) {
            SiteType::Php => <<<CONF
docRoot                   \$VH_ROOT/public/
vhDomain                  {$hostnames->implode(',')}
vhAliases                 www.{$hostnames->first()}
adminEmails               root@localhost
enableGzip                1
index  {
  useServer               0
  indexFiles              index.php, index.html
}
errorlog \$VH_ROOT/logs/error.log {
  useServer               0
  logLevel                WARN
}
accesslog \$VH_ROOT/logs/access.log {
  useServer               0
  logFormat               "%h %l %u %t \"%r\" %>s %b"
}
scripthandler  {
  add                     lsapi:{$this->configName($site)} php
}
extprocessor {$this->configName($site)} {
  type                    lsapi
  address                 uds://tmp/lshttpd/{$this->configName($site)}.sock
  maxConns                10
  env                     PHP_LSAPI_CHILDREN=10
  initTimeout             60
  retryTimeout            0
  persistConn             1
  path                    {$phpBinary}
  backlog                 100
  instances               1
  extUser                 nobody
  extGroup                nogroup
  runOnStartUp            3
}
{$this->rewriteBlock($site)}
context / {
  type                    appserver
  location                {$root}
  allowBrowse             1
}
CONF,
            SiteType::Static => <<<CONF
docRoot                   {$root}/
vhDomain                  {$hostnames->implode(',')}
adminEmails               root@localhost
enableGzip                1
index  {
  useServer               0
  indexFiles              index.html
}
{$this->rewriteBlock($site)}
errorlog {$vhostRoot}/logs/error.log {
  useServer               0
  logLevel                WARN
}
accesslog {$vhostRoot}/logs/access.log {
  useServer               0
  logFormat               "%h %l %u %t \"%r\" %>s %b"
}
CONF,
            SiteType::Node => <<<CONF
docRoot                   {$vhostRoot}/
vhDomain                  {$hostnames->implode(',')}
adminEmails               root@localhost
enableGzip                1
{$this->rewriteBlock($site, $site->app_port)}
errorlog {$vhostRoot}/logs/error.log {
  useServer               0
  logLevel                WARN
}
accesslog {$vhostRoot}/logs/access.log {
  useServer               0
  logFormat               "%h %l %u %t \"%r\" %>s %b"
}
CONF,
        };
    }

    private function buildSuspendedVhost(Site $site, Collection $hostnames, string $vhostRoot): string
    {
        $root = $site->suspendedStaticRoot();

        return <<<CONF
docRoot                   {$root}/
vhDomain                  {$hostnames->implode(',')}
adminEmails               root@localhost
enableGzip                1
index  {
  useServer               0
  indexFiles              index.html
}
errorlog {$vhostRoot}/logs/error.log {
  useServer               0
  logLevel                WARN
}
accesslog {$vhostRoot}/logs/access.log {
  useServer               0
  logFormat               "%h %l %u %t \"%r\" %>s %b"
}
CONF;
    }

    private function buildPhpOctaneProxy(Site $site, Collection $hostnames, string $vhostRoot): string
    {
        $oct = (int) $site->octane_port;

        return <<<CONF
docRoot                   {$vhostRoot}/
vhDomain                  {$hostnames->implode(',')}
adminEmails               root@localhost
enableGzip                1
{$this->rewriteBlock($site, $oct)}
errorlog {$vhostRoot}/logs/error.log {
  useServer               0
  logLevel                WARN
}
accesslog {$vhostRoot}/logs/access.log {
  useServer               0
  logFormat               "%h %l %u %t \"%r\" %>s %b"
}
CONF;
    }

    private function configName(Site $site): string
    {
        return str_replace(['.', '-'], '_', $site->webserverConfigBasename());
    }

    private function rewriteBlock(Site $site, ?int $proxyPort = null): string
    {
        $rules = collect();
        $olsHeaderNote = false;
        foreach ($site->redirects->sortBy('sort_order') as $redirect) {
            $from = SiteRedirectConfigSupport::sanitizeFromPath((string) $redirect->from_path);
            if ($from === '') {
                continue;
            }
            $fromTail = ltrim($from, '/');
            $kind = $redirect->kind instanceof SiteRedirectKind ? $redirect->kind : SiteRedirectKind::Http;
            if ($kind === SiteRedirectKind::InternalRewrite) {
                $to = SiteRedirectConfigSupport::sanitizeInternalTarget((string) $redirect->to_url);
                if ($to === '') {
                    continue;
                }
                $rules->push(sprintf('RewriteRule ^%s$ %s [L]', preg_quote($fromTail, '#'), $to));

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
            if (SiteRedirectConfigSupport::normalizeResponseHeaders($redirect->response_headers ?? null) !== []) {
                $olsHeaderNote = true;
            }
            $rules->push(sprintf('RewriteRule ^%s$ %s [R=%d,L]', preg_quote($fromTail, '#'), $to, $code));
        }
        if ($olsHeaderNote) {
            $rules->prepend('# Dply: custom response headers on HTTP redirects are not applied by Open LiteSpeed; use application headers or nginx/Caddy/Apache as the edge.');
        }

        if ($site->type === SiteType::Php && $site->shouldProxyReverbInWebserver()) {
            $ws = trim($site->reverbWebSocketPath(), '/');
            $rp = $site->reverbLocalPort();
            $rules->push(sprintf(
                'RewriteRule ^%s(.*)$ http://127.0.0.1:%d/%s$1 [P,L]',
                preg_quote($ws, '/'),
                $rp,
                $ws
            ));
        }

        if ($proxyPort !== null) {
            $rules->push(sprintf('RewriteRule ^(.*)$ http://127.0.0.1:%d/$1 [P,L]', $proxyPort));
        }

        if ($rules->isEmpty()) {
            return '';
        }

        return <<<CONF
rewrite  {
  enable                  1
  rules                   <<<END_rules
{$rules->implode("\n")}
END_rules
}
CONF;
    }
}
