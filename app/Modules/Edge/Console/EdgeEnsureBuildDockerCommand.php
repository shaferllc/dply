<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Modules\Edge\Support\EdgeBuildDockerBootstrap;
use Illuminate\Console\Command;

/**
 * One-shot bootstrap for the Linux host that drains Edge build jobs.
 *
 * Horizon typically runs as forge/www-data/dply without passwordless sudo, so
 * deploy-time autoinstall in EdgeBuildRunner fails. Run this once as root on
 * the control-plane / build worker, then recycle queue workers.
 *
 *   sudo php artisan dply:edge:ensure-build-docker --user=forge
 */
class EdgeEnsureBuildDockerCommand extends Command
{
    protected $signature = 'dply:edge:ensure-build-docker
                            {--user= : Linux user that runs Horizon / queue:work (default: current user)}
                            {--check : Only probe the daemon; do not install}';

    protected $description = 'Install/start Docker Engine on this host for Edge builds (run as root on prod workers)';

    public function handle(): int
    {
        if ($this->option('check')) {
            if (EdgeBuildDockerBootstrap::daemonReachable()) {
                $this->info('Docker daemon reachable ('.EdgeBuildDockerBootstrap::probeDetail().').');

                return self::SUCCESS;
            }

            $this->error('Docker daemon not reachable: '.EdgeBuildDockerBootstrap::probeDetail());
            $this->line('Fix: sudo php artisan dply:edge:ensure-build-docker --user=<horizon-user>');

            return self::FAILURE;
        }

        if (EdgeBuildDockerBootstrap::daemonReachable()) {
            $this->info('Docker already reachable ('.EdgeBuildDockerBootstrap::probeDetail().').');
            $user = $this->resolveUser();
            $this->line("Ensuring socket access for {$user}…");
        }

        if (EdgeBuildDockerBootstrap::isLocalDesktopEnvironment()) {
            $this->error('This command installs Linux Docker Engine. On macOS start OrbStack/Docker Desktop, or use DPLY_FAKE_EDGE=true.');

            return self::FAILURE;
        }

        $user = $this->resolveUser();
        $this->info("Installing/starting Docker for queue user [{$user}]…");

        $result = EdgeBuildDockerBootstrap::ensure(
            $user,
            fn (string $chunk) => $this->output->write($chunk),
        );

        if (! $result['ok']) {
            $this->newLine();
            $this->error('Docker is still not reachable: '.$result['detail']);
            if ($result['exit_code'] === 42) {
                $this->line('Re-run as root: sudo php artisan dply:edge:ensure-build-docker --user='.$user);
            }

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Docker ready ('.$result['detail'].').');
        $this->warn('Restart Horizon / queue workers so they pick up the docker group: php artisan horizon:terminate');

        return self::SUCCESS;
    }

    private function resolveUser(): string
    {
        $option = $this->option('user');
        if (is_string($option) && trim($option) !== '') {
            return trim($option);
        }

        $envUser = trim((string) env('DPLY_EDGE_BUILD_DOCKER_USER', ''));
        if ($envUser !== '') {
            return $envUser;
        }

        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $pw = posix_getpwuid(posix_geteuid());
            if (is_array($pw) && is_string($pw['name'] ?? null) && $pw['name'] !== '' && $pw['name'] !== 'root') {
                return $pw['name'];
            }
        }

        $current = trim((string) get_current_user());

        return $current !== '' ? $current : 'forge';
    }
}
