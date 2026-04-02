<?php

namespace App\Services\Sites;

use App\Enums\SiteType;
use App\Models\Site;

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

        $root = $site->effectiveDocumentRoot();
        $phpBinary = '/usr/local/lsws/lsphp'.str_replace('.', '', (string) ($site->php_version ?? '83')).'/bin/lsphp';
        $vhostRoot = rtrim($site->effectiveRepositoryPath(), '/');

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

    private function configName(Site $site): string
    {
        return str_replace(['.', '-'], '_', $site->webserverConfigBasename());
    }

    private function rewriteBlock(Site $site, ?int $appPort = null): string
    {
        $rules = $site->redirects
            ->map(fn ($redirect): string => sprintf(
                'RewriteRule ^%s$ %s [R=%d,L]',
                ltrim($redirect->from_path, '/'),
                $redirect->to_url,
                $redirect->status_code,
            ))
            ->values();

        if ($appPort !== null) {
            $rules->push(sprintf('RewriteRule ^(.*)$ http://127.0.0.1:%d$1 [P,L]', $appPort));
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
