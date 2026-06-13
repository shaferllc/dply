<?php

declare(strict_types=1);

namespace App\Services\Servers\Concerns;

use App\Support\Servers\DedicatedCacheServerProvisionConfig;
use App\Support\Servers\DedicatedDatabaseServerProvisionConfig;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait BuildsProvisionRoles
{


    /**
     * @return list<string>
     */
    private function roleApplication(string $web, string $php, string $database, string $cache, array $layout): array
    {
        $miseParallel = (bool) config('server_provision.parallel_runtimes', false);

        $lines = [];
        $lines = array_merge($lines, $this->ufwSsh());
        $lines = array_merge($lines, $this->installWebserver($web));
        // Parallel mode: launch mise + its (lock-free) runtime downloads BEFORE
        // the apt-heavy PHP/DB/cache steps so they overlap. Sequential mode keeps
        // mise in its original late position (no behaviour change).
        if ($miseParallel) {
            $lines = array_merge($lines, $this->maybeInstallMise());
        }
        $lines = array_merge($lines, $this->installPhpIfNeeded($web, $php, $database));
        $lines = array_merge($lines, $this->installDatabaseIfNeeded($database));
        $lines = array_merge($lines, $this->installAppCache($cache));
        $lines = array_merge($lines, $this->maybeInstallSupervisor());
        if (! $miseParallel) {
            $lines = array_merge($lines, $this->maybeInstallMise());
        }
        $lines = array_merge($lines, $this->writeRenderedConfigs('application', $web, $php, $layout));
        $lines = array_merge($lines, $this->installComposerIfNeeded($php));

        return $lines;
    }

    /**
     * Install Composer system-wide when PHP is present. Shared by every role
     * that installs PHP (application, worker) so deploys never land on a box
     * with PHP but no Composer — otherwise the deploy-time self-heal kicks in
     * and installs it per-user under ~/.local/bin instead.
     *
     * @return list<string>
     */
    private function installComposerIfNeeded(string $php): array
    {
        if (! config('server_provision.install_composer', true) || $php === 'none') {
            return [];
        }

        return [
            $this->stepMarker('Installing Composer'),
            $this->forceReinstall()
                ? 'curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer'
                : 'if command -v composer >/dev/null 2>&1; then echo "[dply] composer already installed; skipping installer."; else curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer; fi',
        ];
    }

    /**
     * @return list<string>
     */
    private function roleDocker(string $web, string $php, string $database, string $cache, array $layout): array
    {
        $lines = $this->withStep('Installing Docker', [
            ...$this->ensurePackagesInstalled(
                ['docker.io', 'docker-compose-v2'],
                '[dply] docker packages already installed; skipping package install.'
            ),
            'systemctl enable --now docker',
        ]);

        $lines[] = 'echo '.escapeshellarg(self::VERIFY_PREFIX.'docker :: ok :: Container host packages installed');

        return array_merge($lines, $this->writeRenderedConfigs('docker', $web, $php, $layout));
    }

    /**
     * @return list<string>
     */
    private function roleWorker(string $web, string $php, string $database, array $layout): array
    {
        // Worker hosts install Caddy so sites deploy through the same
        // vhost-and-FPM pipeline as application hosts. The server-default
        // Caddyfile is a placeholder catch-all — per-site vhosts under
        // /etc/caddy/sites-enabled/ take over for real hostnames.
        $miseParallel = (bool) config('server_provision.parallel_runtimes', false);

        $lines = [];
        $lines = array_merge($lines, $this->ufwSsh());
        $lines = array_merge($lines, $this->installWebserver($web));
        if ($miseParallel) {
            $lines = array_merge($lines, $this->maybeInstallMise());
        }
        $lines = array_merge($lines, $this->installPhpIfNeeded($web, $php, $database));
        $lines = array_merge($lines, $this->installDatabaseIfNeeded($database));
        $lines = array_merge($lines, $this->maybeInstallSupervisor());
        if (! $miseParallel) {
            $lines = array_merge($lines, $this->maybeInstallMise());
        }
        $lines = array_merge($lines, $this->writeRenderedConfigs('worker', $web, $php, $layout));
        $lines = array_merge($lines, $this->installComposerIfNeeded($php));

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function roleDatabase(string $database, array $layout): array
    {
        $config = DedicatedDatabaseServerProvisionConfig::fromServer($this->server, $database);

        $lines = [];
        $lines = array_merge($lines, $this->ufwSsh());
        $lines = array_merge($lines, $this->installDatabaseIfNeeded($database));

        if ($config->remoteAccess && DedicatedDatabaseServerProvisionConfig::engineSupportsRemoteAccess($database)) {
            $lines = array_merge($lines, $config->bootstrapLines());
            $lines = array_merge($lines, $config->ufwAllowLines());
        } else {
            $lines[] = 'ufw deny 3306/tcp || true';
            $lines[] = 'ufw deny 5432/tcp || true';
            if (DedicatedDatabaseServerProvisionConfig::supportsBootstrapCredentials($database)) {
                $lines = array_merge($lines, $config->bootstrapLines());
            }
        }

        $lines = array_merge($lines, $this->writeRenderedConfigs('database', 'none', 'none', $layout));

        return $lines;
    }

    /** @return list<string> */
    private function roleCacheHost(string $cache): array
    {
        $engine = $cache === '' || $cache === 'none' ? 'redis' : $cache;
        $config = DedicatedCacheServerProvisionConfig::fromServer($this->server, $engine);

        return array_merge(
            $this->ufwSsh(),
            $this->installAppCache($engine, $config),
            $config->ufwAllowLines(),
        );
    }

    /** @return list<string> */
    private function roleRedis(): array
    {
        return $this->roleCacheHost('redis');
    }

    /** @return list<string> */
    private function roleValkey(): array
    {
        return $this->roleCacheHost('valkey');
    }

    /** @return list<string> */
    private function roleLoadBalancer(array $layout): array
    {
        $lines = $this->ufwSsh();
        // certbot deferred off the critical path when configured (issuance
        // ensures it on first use); haproxy itself always installs here.
        $lbPackages = (bool) config('server_provision.defer_certbot', false)
            ? ['haproxy']
            : ['haproxy', 'certbot'];
        $lines = array_merge($lines, $this->ensurePackagesInstalled(
            $lbPackages,
            '[dply] load balancer packages already installed; skipping package install.'
        ));
        $lines = array_merge($lines, $this->writeRenderedConfigs('load_balancer', 'none', 'none', $layout));
        $lines[] = 'systemctl enable --now haproxy';
        $lines[] = 'ufw allow 80/tcp';
        $lines[] = 'ufw allow 443/tcp';

        return $lines;
    }

    /** @return list<string> */
    private function rolePlain(array $layout): array
    {
        $lines = $this->ufwSsh();
        $lines = array_merge($lines, $this->maybeInstallSupervisor());
        $lines = array_merge($lines, $this->writeRenderedConfigs('plain', 'none', 'none', $layout));

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function roleHardening(string $role): array
    {
        return match ($role) {
            'database' => $this->withStep('Applying role hardening', [
                'install -d -m 0755 /etc/sysctl.d',
                'printf "vm.swappiness=10\nnet.ipv4.tcp_syncookies=1\n" > /etc/sysctl.d/99-dply-database.conf',
                'sysctl --system >/dev/null 2>&1 || true',
            ]),
            'load_balancer' => $this->withStep('Applying role hardening', [
                'printf "net.core.somaxconn=4096\n" > /etc/sysctl.d/99-dply-haproxy.conf',
                'sysctl --system >/dev/null 2>&1 || true',
            ]),
            default => $this->withStep('Applying role hardening', [
                'printf "fs.inotify.max_user_watches=524288\n" > /etc/sysctl.d/99-dply-app.conf',
                'sysctl --system >/dev/null 2>&1 || true',
            ]),
        };
    }
}
