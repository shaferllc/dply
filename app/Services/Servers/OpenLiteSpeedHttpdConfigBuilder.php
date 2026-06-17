<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Enums\SiteType;
use App\Models\Site;
use App\Support\Sites\OpenLiteSpeedTlsPaths;
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
     * @param  array<string, mixed> $listenerTls
     */
    public function build(Collection $sites, int $listenPort, string $vhostsPath = '/usr/local/lsws/conf/vhosts', ?array $listenerTls = null): string
    {
        $vhostsPath = rtrim($vhostsPath, '/');

        $vhTemplates = $sites
            ->map(fn (Site $site): string => $this->virtualHostBlock($site, $vhostsPath))
            ->implode("\n");

        $tlsEnabled = $listenPort === 80 && $sites->contains(
            fn (Site $site): bool => OpenLiteSpeedTlsPaths::resolve($site) !== null,
        );

        $defaultTls = $listenerTls ?? ($tlsEnabled ? $this->resolveDefaultTlsMaterial($sites) : null);

        $defaultMaps = $this->listenerMapLines($sites, $listenPort, sslListener: false);

        $listeners = <<<CONF
listener Default {
  address                 *:{$listenPort}
  secure                  0{$defaultMaps}
}
CONF;

        if ($tlsEnabled && $defaultTls !== null) {
            $sslMaps = $this->listenerMapLines($sites, $listenPort, sslListener: true);
            $listeners .= <<<CONF


listener DefaultSsl {
  address                 *:443
  secure                  1
  keyFile                 {$defaultTls['keyFile']}
  certFile                {$defaultTls['certFile']}{$sslMaps}
}
CONF;
        }

        $phpProcessors = $this->lsapiProcessorBlocks($sites);
        $runAs = trim((string) config('server_provision.deploy_ssh_user', 'dply'));
        if ($runAs === '') {
            $runAs = 'dply';
        }

        $cacheModule = app(OpenLiteSpeedCacheModuleConfig::class)
            ->renderBlock(OpenLiteSpeedCacheModuleConfig::defaultValues());
        $perfModules = trim(OpenLiteSpeedModulesConfig::DEFAULT_BLOCKS['modgzip'] ?? '');

        return <<<CONF
# Managed by Dply — do NOT hand-edit. Regenerated on every webserver switch.
serverName                dply-managed
user                      {$runAs}
group                     {$runAs}
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

expires {
  enableExpires           1
  expiresByType           image/*=A604800,text/css=A604800,application/x-javascript=A604800,application/javascript=A604800,font/*=A604800,application/x-font-ttf=A604800
}

indexFiles                index.html, index.php

tuning  {
  maxConnections          10000
  connTimeout             300
  maxKeepAliveReq         10000
  keepAliveTimeout        5
  enableGzipCompress      1
  gzipCompressLevel       6
}

{$cacheModule}
{$perfModules}

{$phpProcessors}

{$listeners}

{$vhTemplates}
CONF;
    }

    /**
     * Stock OLS ships a server-level `lsphp` external app. Per-vhost suEXEC
     * extprocessors need a working cgid binary; dply uses these shared handlers
     * with setUIDMode 0 on each virtualhost instead.
     *
     * @param  Collection<int, Site>  $sites
     */
    private function lsapiProcessorBlocks(Collection $sites): string
    {
        $versions = $sites
            ->filter(fn (Site $site): bool => $site->type === SiteType::Php && ! $site->octane_port)
            ->map(fn (Site $site): string => str_replace('.', '', (string) ($site->phpVersion() ?? '83')))
            ->unique()
            ->values();

        if ($versions->isEmpty()) {
            $versions = collect(['83']);
        }

        return $versions
            ->map(fn (string $version): string => $this->lsapiProcessorBlock($version))
            ->implode("\n\n");
    }

    private function lsapiProcessorBlock(string $versionDigits): string
    {
        $name = 'lsphp'.$versionDigits;

        return <<<CONF
extprocessor {$name} {
  type                    lsapi
  address                 uds://tmp/lshttpd/{$name}.sock
  maxConns                10
  env                     PHP_LSAPI_CHILDREN=10
  env                     LSAPI_AVOID_FORK=200M
  initTimeout             60
  retryTimeout            0
  persistConn             1
  respBuffer              0
  autoStart               0
  path                    lsphp{$versionDigits}/bin/lsphp
  backlog                 100
  instances               1
  priority                0
  memSoftLimit            0
  memHardLimit            0
  procSoftLimit           1400
  procHardLimit           1500
}
CONF;
    }

    /**
     * Native virtualhost stanza — one per site. dply uses direct configFile
     * pointers (plain vhconf.conf) rather than shared vhTemplate members.
     */
    private function virtualHostBlock(Site $site, string $vhostsPath): string
    {
        $basename = $site->webserverConfigBasename();
        $configFile = $vhostsPath.'/'.$basename.'/vhconf.conf';
        $vhRoot = rtrim($site->effectiveRepositoryPath(), '/');

        return <<<CONF
virtualhost {$basename} {
  vhRoot                  {$vhRoot}
  configFile              {$configFile}
  allowSymbolLink         1
  enableScript            1
  restrained              0
  enableGzip              1
  setUIDMode              0
}
CONF;
    }

    /**
     * OLS refuses to bind :443 unless the secure listener has key/cert
     * material, even when member vhosts declare vhssl for SNI.
     *
     * @param  Collection<int, Site>  $sites
     * @return array{keyFile: string, certFile: string}|null
     */
    private function resolveDefaultTlsMaterial(Collection $sites): ?array
    {
        foreach ($sites as $site) {
            $paths = OpenLiteSpeedTlsPaths::resolve($site);
            if ($paths !== null) {
                return $paths;
            }
        }

        return null;
    }

    /**
     * Listener `map` rows wire hostnames to vhTemplate members. Without
     * these, OLS serves its stock 404 page even when vhDomain is set.
     *
     * @param  Collection<int, Site>  $sites
     */
    private function listenerMapLines(Collection $sites, int $listenPort, bool $sslListener): string
    {
        $lines = [];
        foreach ($sites as $site) {
            if ($sslListener && ($listenPort !== 80 || OpenLiteSpeedTlsPaths::resolve($site) === null)) {
                continue;
            }

            $basename = $site->webserverConfigBasename();
            foreach ($site->webserverHostnames() as $hostname) {
                $hostname = strtolower(trim($hostname));
                if ($hostname === '') {
                    continue;
                }
                $lines[] = "  map                     {$basename} {$hostname}";
            }
        }

        if ($lines === []) {
            return '';
        }

        return "\n".implode("\n", array_values(array_unique($lines)));
    }
}
