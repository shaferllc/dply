<?php

namespace App\Services\Sites;

use App\Models\ConsoleAction;
use App\Models\Site;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\Sites\Contracts\SiteWebserverProvisioner;
use Illuminate\Support\Str;

class SiteOpenLiteSpeedProvisioner extends AbstractSiteWebserverProvisioner implements SiteWebserverProvisioner
{
    public function __construct(
        protected OpenLiteSpeedSiteConfigBuilder $builder,
        ?SitePlaceholderPageBuilder $placeholderPageBuilder = null,
    ) {
        parent::__construct($placeholderPageBuilder);
    }

    public function webserver(): string
    {
        return 'openlitespeed';
    }

    public function provision(Site $site, ?ConsoleEmitter $emit = null): string
    {
        $emit ??= new ConsoleEmitter;

        $emit->step('openlitespeed', 'resolving server connection');
        $server = $this->ensureServerReady($site);
        $basename = $this->configBasename($site);
        $vhostsPath = rtrim(config('sites.openlitespeed_vhosts_path'), '/');
        $configFile = $vhostsPath.'/'.$basename.'/vhconf.conf';
        $httpdConfig = (string) config('sites.openlitespeed_httpd_config');

        $ssh = $this->systemSsh($site);
        // First-apply only — meta.openlitespeed_last_output is written at the
        // end of the first successful provision, so subsequent applies skip
        // the placeholder probe entirely.
        if (! isset(($site->meta ?? [])['openlitespeed_last_output'])) {
            $this->installPlaceholderPage($site, $ssh, $emit);
        }
        $this->ensureSuspendedPage($site, $ssh, $emit);
        $this->syncBasicAuthHtpasswdFiles($site, $ssh, $emit);
        if ($this->writeSystemFileIfChanged($server, $ssh, $configFile, $this->builder->build($site))) {
            $emit->step('openlitespeed', 'writing site config: '.$configFile);
        }

        $includeBlock = sprintf(
            "\nvhTemplate %s {\n  templateFile %s\n  listeners Default\n  vhDomain %s\n}\n",
            $basename,
            $configFile,
            implode(',', $site->webserverHostnames())
        );

        $emit->step('openlitespeed', 'restarting lsws');
        $out = $ssh->exec(sprintf(
            '(%s) 2>&1; printf "\nDPLY_OLS_EXIT:%%s" "$?"',
            $this->privilegedCommand(
                $server,
                sprintf(
                    'mkdir -p %1$s/%2$s %3$s && (grep -Fqx %4$s %5$s || printf "%%s" %6$s >> %5$s) && /usr/local/lsws/bin/lswsctrl restart',
                    escapeshellarg($vhostsPath),
                    escapeshellarg($basename),
                    escapeshellarg(rtrim($site->effectiveRepositoryPath(), '/').'/logs'),
                    escapeshellarg('templateFile '.$configFile),
                    escapeshellarg($httpdConfig),
                    escapeshellarg($includeBlock)
                )
            )
        ), 180);

        foreach (preg_split('/\r\n|\r|\n/', trim($out)) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $emit($line, ConsoleAction::LEVEL_INFO, 'openlitespeed');
        }

        if (! preg_match('/DPLY_OLS_EXIT:0\s*$/', $out)) {
            throw new \RuntimeException('OpenLiteSpeed config update failed. Output: '.Str::limit($out, 2000));
        }

        $emit->success('lsws restart OK', 'openlitespeed');
        $this->updateSiteMeta($site, 'openlitespeed_last_output', $out);

        return $out;
    }

    public function readCurrentSiteConfig(Site $site): ?string
    {
        $server = $this->ensureServerReady($site);
        $ssh = $this->systemSsh($site);
        $basename = $this->configBasename($site);
        $vhostsPath = rtrim(config('sites.openlitespeed_vhosts_path'), '/');
        $configFile = $vhostsPath.'/'.$basename.'/vhconf.conf';

        return $this->readRemoteFile($server, $ssh, $configFile);
    }

    public function remove(Site $site): string
    {
        $server = $this->ensureServerReady($site);
        $basename = $this->configBasename($site);
        $vhostsPath = rtrim(config('sites.openlitespeed_vhosts_path'), '/');

        $ssh = $this->systemSsh($site);
        $out = $ssh->exec(sprintf(
            '(%s) 2>&1; printf "\nDPLY_OLS_REMOVE_EXIT:%%s" "$?"',
            $this->privilegedCommand(
                $server,
                sprintf(
                    'rm -rf %s && /usr/local/lsws/bin/lswsctrl restart',
                    escapeshellarg($vhostsPath.'/'.$basename)
                )
            )
        ), 180);

        if (! preg_match('/DPLY_OLS_REMOVE_EXIT:0\s*$/', $out)) {
            throw new \RuntimeException('OpenLiteSpeed cleanup failed. Output: '.Str::limit($out, 2000));
        }

        $this->updateSiteMeta($site, 'openlitespeed_cleanup_output', $out);

        return $out;
    }
}
