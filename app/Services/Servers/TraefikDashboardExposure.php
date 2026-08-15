<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Services\SshConnection;

/**
 * Exposes Traefik's dashboard on the public `web` entry point via a dynamic file route.
 * Localhost API on :9094 remains for dply live-state probes.
 */
class TraefikDashboardExposure
{
    public const MANAGED_PATH = '/etc/traefik/dynamic/dply-dashboard.yml';

    /**
     * @return array{enabled: bool, path: string, username: string, has_password: bool, auth_user_line: ?string}
     */
    public function read(Server $server): array
    {
        $defaults = ['enabled' => false, 'path' => '/traefik-dashboard', 'username' => '', 'has_password' => false, 'auth_user_line' => null];

        try {
            $ssh = new SshConnection($server);
            $contents = $ssh->exec('sudo -n cat '.escapeshellarg(self::MANAGED_PATH).' 2>/dev/null', 15);
            if ($contents === '' || $ssh->lastExecExitCode() !== 0) {
                return $defaults;
            }
        } catch (\Throwable) {
            return $defaults;
        }

        $enabled = str_contains($contents, 'dply-dashboard:');
        $path = '/traefik-dashboard';
        if (preg_match('/prefixes:\s*\[\s*["\']([^"\']+)["\']/', $contents, $m) === 1) {
            $path = (string) $m[1];
        }
        $username = '';
        $hasPassword = false;
        $authUserLine = null;
        if (preg_match('/users:\s*\n\s*-\s*["\']([^"\']+)["\']/', $contents, $um) === 1) {
            $authUserLine = (string) $um[1];
            if (preg_match('/^([^:]+):/', $authUserLine, $nm) === 1) {
                $username = (string) $nm[1];
                $hasPassword = true;
            }
        }

        return [
            'enabled' => $enabled,
            'path' => $path,
            'username' => $username,
            'has_password' => $hasPassword,
            'auth_user_line' => $authUserLine,
        ];
    }

    /**
     * HTTP URL on the server's public web entry point when exposure is enabled.
     */
    public function publicUrl(Server $server): ?string
    {
        $state = $this->read($server);
        if (! $state['enabled']) {
            return null;
        }

        $ip = trim((string) $server->ip_address);
        if ($ip === '') {
            return null;
        }

        $path = rtrim((string) $state['path'], '/');

        return 'http://'.$ip.$path.'/';
    }

    /**
     * @param  array{enabled: bool, path: string, username?: string, password?: string}  $options
     *
     * @throws \RuntimeException
     */
    public function sync(Server $server, array $options, ?ConsoleEmitter $emitter = null): void
    {
        $emit = $emitter ?? new ConsoleEmitter(null);
        $enabled = ! empty($options['enabled']);
        $path = $this->normalizePath((string) ($options['path'] ?? '/traefik-dashboard'));

        if (! $enabled) {
            $this->remove($server, $emit);

            return;
        }

        $username = trim((string) ($options['username'] ?? ''));
        $password = (string) ($options['password'] ?? '');
        $existing = $this->read($server);
        if ($password === '' && $username !== '' && ! empty($existing['auth_user_line'])) {
            $existingUser = explode(':', (string) $existing['auth_user_line'], 2)[0] ?? '';
            if ($existingUser === $username) {
                $contents = $this->renderWithAuthLine($path, (string) $existing['auth_user_line']);
            } else {
                $contents = $this->render($path, $username, $password);
            }
        } else {
            $contents = $this->render($path, $username, $password);
        }
        $this->write($server, $contents, $emit);
        $emit->success(__('Dashboard exposed at :path on the web entry point.', ['path' => $path]));
    }

    private function remove(Server $server, ConsoleEmitter $emit): void
    {
        $ssh = new SshConnection($server);
        $emit->step('traefik-dashboard', 'Removing public dashboard route');
        $ssh->exec('sudo -n rm -f '.escapeshellarg(self::MANAGED_PATH), 10);
        $emit->info(__('Public dashboard route removed. Local API on 127.0.0.1:9094 is unchanged.'));
    }

    private function write(Server $server, string $contents, ConsoleEmitter $emit): void
    {
        $ssh = new SshConnection($server);
        $emit->step('traefik-dashboard', 'Writing '.self::MANAGED_PATH);
        $tmp = '/tmp/dply-traefik-dashboard-'.bin2hex(random_bytes(4));
        $encoded = base64_encode($contents);
        $ssh->exec(sprintf(
            'printf %s | base64 -d | sudo -n tee %s > /dev/null && sudo -n chmod 0644 %s',
            escapeshellarg($encoded),
            escapeshellarg($tmp),
            escapeshellarg($tmp),
        ), 15);
        $ssh->exec(sprintf(
            'sudo -n install -m 0644 -T %s %s',
            escapeshellarg($tmp),
            escapeshellarg(self::MANAGED_PATH),
        ), 10);
        $ssh->exec('sudo -n rm -f '.escapeshellarg($tmp), 5);
        if ($ssh->lastExecExitCode() !== 0) {
            throw new \RuntimeException('Failed to write dashboard route file.');
        }
    }

    private function renderWithAuthLine(string $path, string $authUserLine): string
    {
        $prefix = rtrim($path, '/');
        $hash = str_replace('"', '\\"', $authUserLine);

        return <<<YAML
# Managed by Dply — public Traefik dashboard route (hot-reloaded).
http:
  routers:
    dply-dashboard:
      rule: "PathPrefix(`{$prefix}`)"
      entryPoints:
        - web
      service: api@internal
      priority: 2000
      middlewares:
      - dply-dashboard-strip
      - dply-dashboard-auth
  middlewares:
    dply-dashboard-strip:
      stripPrefix:
        prefixes:
          - "{$prefix}"
    dply-dashboard-auth:
      basicAuth:
        users:
          - "{$hash}"
YAML;
    }

    private function render(string $path, string $username, string $password): string
    {
        $prefix = rtrim($path, '/');
        $authBlock = '';
        $routerMiddlewares = "      - dply-dashboard-strip\n";
        if ($username !== '' && $password !== '') {
            $hash = $this->htpasswdApr1($username, $password);
            $authBlock = <<<YAML

    dply-dashboard-auth:
      basicAuth:
        users:
          - "{$username}:{$hash}"
YAML;
            $routerMiddlewares .= "      - dply-dashboard-auth\n";
        }

        return <<<YAML
# Managed by Dply — public Traefik dashboard route (hot-reloaded).
http:
  routers:
    dply-dashboard:
      rule: "PathPrefix(`{$prefix}`)"
      entryPoints:
        - web
      service: api@internal
      priority: 2000
      middlewares:
{$routerMiddlewares}  middlewares:
    dply-dashboard-strip:
      stripPrefix:
        prefixes:
          - "{$prefix}"
{$authBlock}
YAML;
    }

    private function normalizePath(string $path): string
    {
        $path = '/'.trim($path, '/');
        if ($path === '/') {
            return '/traefik-dashboard';
        }

        return $path;
    }

    private function htpasswdApr1(string $user, string $password): string
    {
        $salt = substr(str_replace(['+', '/'], ['x', 'y'], base64_encode(random_bytes(6))), 0, 8);
        $hash = crypt($password, '$apr1$'.$salt);

        return (string) $hash;
    }
}
