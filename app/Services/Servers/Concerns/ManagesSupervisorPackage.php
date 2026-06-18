<?php

declare(strict_types=1);

namespace App\Services\Servers\Concerns;

use App\Models\Server;
use App\Services\Servers\ServerSshConnectionRunner;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesSupervisorPackage
{


    /**
     * Whether the Debian/Ubuntu supervisor package is installed (dpkg).
     */
    public function isSupervisorPackageInstalled(Server $server): bool
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            return false;
        }

        try {
            $out = app(ServerSshConnectionRunner::class)->run(
                $server,
                fn ($ssh): string => $ssh->exec('dpkg-query -W -f=\'${Status}\' supervisor 2>/dev/null || true', 30),
                $this->useRootSsh(),
                $this->fallbackToDeployUserSsh()
            );
        } catch (\Throwable) {
            return false;
        }

        return str_contains($out, 'ok installed');
    }

    /**
     * apt install supervisor + enable service (runs as root SSH user, or sudo for deploy user).
     *
     * @throws \RuntimeException
     */
    public function installSupervisorPackage(Server $server): string
    {
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        // After install, patch the main supervisord.conf to add user=root so the
        // "Supervisor is running as root" CRIT is suppressed on every start.
        $inner = 'export DEBIAN_FRONTEND=noninteractive'
            .' && apt-get update -y'
            .' && apt-get install -y --no-install-recommends supervisor'
            .' && grep -qxF "user=root" /etc/supervisor/supervisord.conf'
            .'    || sed -i "/^\[supervisord\]/a user=root" /etc/supervisor/supervisord.conf'
            .' && systemctl enable --now supervisor';
        $cmd = $this->privilegedBash($server, $inner);

        return app(ServerSshConnectionRunner::class)->run(
            $server,
            fn ($ssh): string => $ssh->exec($cmd.' 2>&1', 900),
            $this->useRootSsh(),
            $this->fallbackToDeployUserSsh()
        );
    }

    /**
     * Wrap a binary with `sudo -n env PATH=…` for non-root SSH users so privileged paths like
     * /usr/sbin (systemctl) and /var/log files owned by root are reachable. Matches the firewall
     * provisioner's pattern.
     */
    protected function privilegedBinaryPrefix(Server $server, string $binary): string
    {
        $user = trim((string) $server->ssh_user);
        if ($user === '' || $user === 'root') {
            return $binary;
        }

        return 'sudo -n env PATH=/usr/sbin:/usr/bin:/sbin:/bin '.$binary;
    }

    /**
     * Shell line for: supervisorctl reread; supervisorctl update (with sudo when SSH user is not root).
     * Deploy users often cannot access supervisord's socket without sudo — matches {@see privilegedBash}.
     */
    public function supervisorRereadUpdateExecLine(Server $server, string $exitLabel = 'DPLY_SV_EXIT'): string
    {
        $sc = $this->supervisorctlInv($server);

        return $sc.' reread 2>&1; '.$sc.' update 2>&1; printf "\n'.$exitLabel.':%s" "$?"';
    }

    /**
     * How to invoke supervisorctl over SSH: plain for root, {@code sudo -n supervisorctl} for other users.
     */
    protected function supervisorctlInv(Server $server): string
    {
        $user = trim((string) $server->ssh_user);
        if ($user === '' || $user === 'root') {
            return 'supervisorctl';
        }

        return 'sudo -n supervisorctl';
    }

    /**
     * Start/stop/restart the Supervisor system service (systemd), or query status / boot flags.
     * Uses {@see privilegedBash} so non-root SSH users run via passwordless sudo, like other server management.
     *
     * @param  string  $action  One of: status, start, stop, restart, reload, is-active, is-enabled, enable, disable
     */
    public function manageSupervisorService(Server $server, string $action): string
    {
        $allowed = ['status', 'start', 'stop', 'restart', 'reload', 'is-active', 'is-enabled', 'enable', 'disable'];
        if (! in_array($action, $allowed, true)) {
            throw new \InvalidArgumentException('Invalid supervisor service action.');
        }
        if (! $server->isReady() || empty($server->ssh_private_key)) {
            throw new \RuntimeException('Server must be ready with an SSH key.');
        }

        $unit = (string) config('sites.supervisor_systemd_unit', 'supervisor');
        $unitEsc = escapeshellarg($unit);

        $inner = match ($action) {
            'is-active', 'is-enabled' => 'systemctl '.$action.' '.$unitEsc.' 2>&1; printf \'\nDPLY_EXIT:%s\' "$?"',
            default => '(systemctl '.$action.' '.$unitEsc.' 2>&1) || (service '.$unitEsc.' '.$action.' 2>&1); printf \'\nDPLY_EXIT:%s\' "$?"',
        };

        return app(ServerSshConnectionRunner::class)->run(
            $server,
            fn ($ssh): string => trim((string) $ssh->exec($this->privilegedBash($server, $inner), 180)),
            $this->useRootSsh(),
            $this->fallbackToDeployUserSsh()
        );
    }
}
