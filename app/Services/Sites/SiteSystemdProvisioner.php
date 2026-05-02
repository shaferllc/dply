<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Contracts\RemoteShell;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteProcess;
use App\Services\SshConnection;
use Closure;
use Illuminate\Support\Str;

/**
 * Uploads systemd unit files for a site via SSH and activates them.
 *
 * Composes {@see SiteSystemdUnitBuilder}'s pure unit content with the
 * existing SSH transport ({@see SshConnection}) to actually write
 * `/etc/systemd/system/dply-site-{id}.service` and run
 * `systemctl daemon-reload && systemctl enable --now …` for each
 * uploaded unit.
 *
 * For non-PHP/static sites this is the bridge that turns a
 * SiteProcess row with a command into a managed Linux service.
 *
 * Testability: the optional $shellFactory closure lets tests pass a
 * fake RemoteShell that records putFile/exec calls. Production callers
 * omit it, and we instantiate {@see SshConnection} from the site's
 * server.
 */
class SiteSystemdProvisioner
{
    public function __construct(
        private SiteSystemdUnitBuilder $builder,
    ) {}

    /**
     * Provision (or refresh) systemd units for the site's processes.
     *
     * Returns the list of unit filenames that were uploaded + activated,
     * for callers that want to log or surface the result.
     *
     * @param  (Closure(Server): RemoteShell)|null  $shellFactory  test seam
     * @return list<string>
     */
    public function provision(Site $site, ?Closure $shellFactory = null): array
    {
        $server = $site->server;
        if ($server === null || ! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $deployUser = $site->effectiveSystemUser($server);
        $units = $this->collectUnits($site, $deployUser);
        if ($units === []) {
            return [];
        }

        $shell = $shellFactory !== null ? $shellFactory($server) : new SshConnection($server);
        $written = [];
        foreach ($units as $unitName => $content) {
            $this->upload($shell, $unitName, $content);
            $written[] = $unitName;
        }

        $shell->exec('sudo systemctl daemon-reload', 30);
        foreach ($written as $unitName) {
            $shell->exec('sudo systemctl enable --now '.escapeshellarg($unitName), 60);
        }

        return $written;
    }

    /**
     * Stop, disable, and remove all systemd units belonging to the site.
     * Called when a site is being deleted; safe to call when units don't
     * exist (systemctl returns non-zero but we don't propagate).
     *
     * @param  (Closure(Server): RemoteShell)|null  $shellFactory
     * @return list<string>
     */
    public function teardown(Site $site, ?Closure $shellFactory = null): array
    {
        $server = $site->server;
        if ($server === null || ! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $unitNames = $this->candidateUnitNames($site);
        if ($unitNames === []) {
            return [];
        }

        $shell = $shellFactory !== null ? $shellFactory($server) : new SshConnection($server);
        foreach ($unitNames as $unitName) {
            $shell->exec(
                'sudo systemctl disable --now '.escapeshellarg($unitName).' || true; '.
                'sudo rm -f /etc/systemd/system/'.escapeshellarg($unitName),
                60,
            );
        }
        $shell->exec('sudo systemctl daemon-reload', 30);

        return $unitNames;
    }

    /**
     * Tear down a single named unit. Used when a specific SiteProcess
     * is deleted — we don't want to teardown all the site's units, just
     * the one whose row went away. The teardown sequence is identical to
     * the bulk version; sharing the inner shell calls keeps the
     * disable+rm+reload semantics consistent.
     *
     * @param  (Closure(Server): RemoteShell)|null  $shellFactory
     */
    public function teardownUnit(Site $site, string $unitName, ?Closure $shellFactory = null): void
    {
        $server = $site->server;
        if ($server === null || ! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $shell = $shellFactory !== null ? $shellFactory($server) : new SshConnection($server);
        $shell->exec(
            'sudo systemctl disable --now '.escapeshellarg($unitName).' || true; '.
            'sudo rm -f /etc/systemd/system/'.escapeshellarg($unitName).' && '.
            'sudo systemctl daemon-reload',
            90,
        );
    }

    /**
     * @return array<string, string> unit filename => content
     */
    private function collectUnits(Site $site, string $deployUser): array
    {
        $units = [];
        $webUnit = $this->builder->buildWebUnit($site, $deployUser);
        if ($webUnit !== null) {
            $units[$this->builder->webUnitName($site)] = $webUnit;
        }

        $site->loadMissing('processes');
        foreach ($site->processes as $process) {
            if ($process->type === SiteProcess::TYPE_WEB) {
                // Web is rendered above (with PORT env); skip the SiteProcess
                // duplicate to avoid two units with the same purpose.
                continue;
            }
            $unit = $this->builder->buildProcessUnit($site, $process, $deployUser);
            if ($unit !== null) {
                $units[$this->builder->processUnitName($site, $process)] = $unit;
            }
        }

        return $units;
    }

    /**
     * @return list<string>
     */
    private function candidateUnitNames(Site $site): array
    {
        $names = [$this->builder->webUnitName($site)];
        $site->loadMissing('processes');
        foreach ($site->processes as $process) {
            if ($process->type === SiteProcess::TYPE_WEB) {
                continue;
            }
            $names[] = $this->builder->processUnitName($site, $process);
        }

        return $names;
    }

    private function upload(RemoteShell $shell, string $unitName, string $content): void
    {
        // Write to tmp first, then sudo-install — matches the SiteEnvPusher
        // pattern, lets us put files into /etc/systemd/system without
        // requiring SFTP-as-root.
        $tmp = '/tmp/dply-unit-'.Str::lower(Str::random(20));
        $shell->putFile($tmp, $content);
        $shell->exec(
            'sudo install -m 0644 '.escapeshellarg($tmp).' '.
            '/etc/systemd/system/'.escapeshellarg($unitName).' && '.
            'rm -f '.escapeshellarg($tmp),
            30,
        );
    }
}
