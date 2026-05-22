<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Site;
use Illuminate\Database\Eloquent\Collection;

/**
 * Renders the top-level `httpd_config.conf` for OpenLiteSpeed. The switch
 * flow uses this to write a dply-owned config when migrating to OLS — the
 * per-site `vhconf.conf` files are portless, so the listener block here
 * is what controls which port OLS binds.
 *
 * `$listenPort` is :8080 during the switch-flow's provision/validate stages
 * (so `lshttpd -t` parses a config that wouldn't conflict with the live
 * webserver on :80) and :80 at cutover.
 *
 * vhRoot on each vhTemplate is set explicitly to the site's effective repo
 * path so `$VH_ROOT` in the vhconf.conf resolves to the same directory
 * dply's site provisioner creates logs/ under. Without it, OLS defaults
 * `$VH_ROOT` to `/usr/local/lsws/conf/vhosts/<name>` and the PHP-site log
 * paths break.
 */
class OpenLiteSpeedHttpdConfigBuilder
{
    /**
     * Build the full httpd_config.conf for the given sites bound to $listenPort.
     *
     * @param  Collection<int, Site>  $sites
     */
    public function build(Collection $sites, int $listenPort, string $vhostsPath = '/usr/local/lsws/conf/vhosts'): string
    {
        $vhostsPath = rtrim($vhostsPath, '/');

        $vhTemplates = $sites
            ->map(fn (Site $site): string => $this->vhTemplateBlock($site, $vhostsPath))
            ->implode("\n");

        return <<<CONF
# Managed by Dply — do NOT hand-edit. Regenerated on every webserver switch.
serverName                dply-managed
user                      nobody
group                     nogroup
priority                  0
inMemBufSize              60M
swappingDir               /tmp/lshttpd/swap
autoFix503                1
gracefulRestartTimeout    300
mime                      conf/mime.properties
showVersionNumber         0
adminEmails               root@localhost

errorlog logs/error.log {
  logLevel                WARN
  debugLevel              0
  rollingSize             10M
  enableStderrLog         1
}

accesslog logs/access.log {
  rollingSize             10M
  keepDays                30
  compressArchive         0
}

indexFiles                index.html, index.php

tuning  {
  maxConnections          10000
  connTimeout             300
  maxKeepAliveReq         10000
  keepAliveTimeout        5
}

listener Default {
  address                 *:{$listenPort}
  secure                  0
}

{$vhTemplates}
CONF;
    }

    /**
     * Per-site vhTemplate block. References the site's vhconf.conf and pins
     * vhRoot to the effective repository path so `$VH_ROOT` substitution in
     * the vhconf matches the directory dply's provisioner uses.
     */
    private function vhTemplateBlock(Site $site, string $vhostsPath): string
    {
        $basename = $site->webserverConfigBasename();
        $templateFile = $vhostsPath.'/'.$basename.'/vhconf.conf';
        $vhRoot = rtrim($site->effectiveRepositoryPath(), '/');
        $vhDomain = implode(',', $site->webserverHostnames());

        return <<<CONF
vhTemplate {$basename} {
  templateFile            {$templateFile}
  listeners               Default
  vhRoot                  {$vhRoot}
  vhDomain                {$vhDomain}
}
CONF;
    }
}
