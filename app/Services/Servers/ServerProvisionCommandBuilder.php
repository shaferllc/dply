<?php

declare(strict_types=1);

namespace App\Services\Servers;

use App\Models\Server;

/**
 * Builds a bash script (list of lines) from servers.meta stack fields set at create time.
 *
 * Target images: Ubuntu 24.04 LTS (default DigitalOcean image) and compatible Debian/Ubuntu.
 */
final class ServerProvisionCommandBuilder
{
    private const STEP_PREFIX = '[dply-step] ';

    private const VERIFY_PREFIX = '[dply-verify] ';

    private const ROLLBACK_PREFIX = '[dply-rollback] ';

    /** @return list<string> */
    public function build(Server $server): array
    {
        $meta = $server->meta ?? [];
        if (! is_array($meta) || empty($meta['server_role']) || ! is_string($meta['server_role'])) {
            return [];
        }

        $role = $meta['server_role'];
        if (! $this->isAllowed('server_roles', $role)) {
            return [];
        }

        $web = $this->coalesceId('webservers', $meta['webserver'] ?? null, 'nginx');
        $php = $this->coalesceId('php_versions', $meta['php_version'] ?? null, '8.3');
        $database = $this->coalesceId('databases', $meta['database'] ?? null, 'mysql84');
        $cache = $this->coalesceId('cache_services', $meta['cache_service'] ?? null, 'redis');
        $layout = $this->deployLayout($server);

        $lines = [];
        $lines[] = 'echo "[dply] provision start role='.$role.' web='.$web.' php='.$php.' db='.$database.' cache='.$cache.'"';

        $lines = array_merge($lines, $this->dplyDeployUserBootstrap($server));
        $lines = array_merge($lines, $this->bootstrap());
        $lines = array_merge($lines, $this->createDeployLayout($layout));
        $lines = array_merge($lines, match ($role) {
            'application' => $this->roleApplication($web, $php, $database, $cache, $layout),
            'docker' => $this->roleDocker($web, $php, $database, $cache, $layout),
            'worker' => $this->roleWorker($php, $database, $layout),
            'database' => $this->roleDatabase($database, $layout),
            'redis' => $this->roleRedis(),
            'valkey' => $this->roleValkey(),
            'load_balancer' => $this->roleLoadBalancer($layout),
            'plain' => $this->rolePlain($layout),
            default => [],
        });

        $lines = array_merge($lines, $this->verificationCommands($role, $web, $php, $database, $cache));
        $lines = array_merge($lines, $this->finalize($role));

        return $lines;
    }

    /**
     * @return list<array{type:string,key:string,label:string,content:string,metadata:array<string,mixed>}>
     */
    public function buildArtifacts(Server $server): array
    {
        $meta = $server->meta ?? [];
        if (! is_array($meta) || empty($meta['server_role']) || ! is_string($meta['server_role'])) {
            return [];
        }

        $role = $meta['server_role'];
        $web = $this->coalesceId('webservers', $meta['webserver'] ?? null, 'nginx');
        $php = $this->coalesceId('php_versions', $meta['php_version'] ?? null, '8.3');
        $database = $this->coalesceId('databases', $meta['database'] ?? null, 'mysql84');
        $cache = $this->coalesceId('cache_services', $meta['cache_service'] ?? null, 'redis');
        $layout = $this->deployLayout($server);

        $artifacts = [[
            'type' => 'deploy_layout',
            'key' => 'layout',
            'label' => 'Deploy layout',
            'content' => json_encode($layout, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
            'metadata' => ['role' => $role],
        ]];

        foreach ($this->renderedConfigs($role, $web, $php, $layout) as $key => $config) {
            $artifacts[] = [
                'type' => 'rendered_config',
                'key' => $key,
                'label' => $config['label'],
                'content' => $config['content'],
                'metadata' => ['path' => $config['path']],
            ];
        }

        $artifacts[] = [
            'type' => 'verification_plan',
            'key' => 'verification-plan',
            'label' => 'Verification plan',
            'content' => implode("\n", $this->verificationLabels($role, $web, $php, $database, $cache)),
            'metadata' => compact('role', 'web', 'php', 'database', 'cache'),
        ];

        $artifacts[] = [
            'type' => 'stack_summary',
            'key' => 'stack-summary',
            'label' => 'Installed stack',
            'content' => json_encode([
                'role' => $role,
                'webserver' => $web,
                'php_version' => $php,
                'database' => $database,
                'cache_service' => $cache,
                'deploy_user' => (string) config('server_provision.deploy_ssh_user', 'dply'),
                'expected_services' => array_keys($this->verificationLabels($role, $web, $php, $database, $cache)),
                'paths' => [
                    'current' => $layout['current'],
                    'shared' => $layout['shared'],
                    'logs' => $layout['logs'],
                ],
                'config_files' => collect($this->renderedConfigs($role, $web, $php, $layout))
                    ->map(fn (array $config): string => $config['path'])
                    ->values()
                    ->all(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
            'metadata' => [
                'role' => $role,
                'webserver' => $web,
                'php_version' => $php,
                'database' => $database,
                'cache_service' => $cache,
                'deploy_user' => (string) config('server_provision.deploy_ssh_user', 'dply'),
                'expected_services' => array_keys($this->verificationLabels($role, $web, $php, $database, $cache)),
                'paths' => [
                    'current' => $layout['current'],
                    'shared' => $layout['shared'],
                    'logs' => $layout['logs'],
                ],
                'config_files' => collect($this->renderedConfigs($role, $web, $php, $layout))
                    ->map(fn (array $config): string => $config['path'])
                    ->values()
                    ->all(),
            ],
        ];

        $artifacts[] = [
            'type' => 'rollback_plan',
            'key' => 'rollback-plan',
            'label' => 'Rollback plan',
            'content' => implode("\n", [
                'Restore config files written through dply_write_file.',
                'Disable generated systemd unit symlinks when they were created in this run.',
                'Remove new config files that did not exist before provisioning.',
            ]),
            'metadata' => ['strategy' => 'best_effort'],
        ];

        return $artifacts;
    }

    /**
     * Create the deploy user with the same SSH key as root (from provisioning) so the app can
     * connect as that user after setup; root remains available on the image.
     *
     * @return list<string>
     */
    private function dplyDeployUserBootstrap(Server $server): array
    {
        $username = (string) config('server_provision.deploy_ssh_user', 'dply');
        if ($username === '' || $username === 'root' || ! preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $username)) {
            return [];
        }

        $pub = $server->openSshPublicKeyFromPrivate();
        if ($pub === null || $pub === '') {
            return [];
        }

        $b64 = base64_encode($pub);

        return $this->withStep('Creating server user', [
            'echo '.escapeshellarg($b64).' | base64 -d > /tmp/dply-ssh-bootstrap.pub',
            'chmod 600 /tmp/dply-ssh-bootstrap.pub',
            'id -u '.escapeshellarg($username).' &>/dev/null || useradd -m -s /bin/bash -G sudo '.escapeshellarg($username),
            'install -d -m 700 -o '.escapeshellarg($username).' -g '.escapeshellarg($username).' /home/'.escapeshellarg($username).'/.ssh',
            'install -m 600 -o '.escapeshellarg($username).' -g '.escapeshellarg($username).' /tmp/dply-ssh-bootstrap.pub /home/'.escapeshellarg($username).'/.ssh/authorized_keys',
            'rm -f /tmp/dply-ssh-bootstrap.pub',
            'printf \'%s\\n\' '.escapeshellarg($username.' ALL=(ALL) NOPASSWD:ALL').' > /etc/sudoers.d/90-dply-user',
            'chmod 440 /etc/sudoers.d/90-dply-user',
        ]);
    }

    /** @return list<string> */
    private function bootstrap(): array
    {
        return [
            'export DEBIAN_FRONTEND=noninteractive',
            $this->stepMarker('Installing system updates'),
            'apt-get update -y',
            $this->stepMarker('Installing base packages'),
            ...$this->ensurePackagesInstalled(
                ['ca-certificates', 'curl', 'gnupg', 'lsb-release', 'software-properties-common', 'ufw', 'unattended-upgrades'],
                '[dply] base packages already installed; skipping package install.'
            ),
        ];
    }

    /**
     * @return list<string>
     */
    private function finalize(string $role): array
    {
        $lines = [];

        if (config('server_provision.install_fail2ban', true)) {
            $lines[] = $this->stepMarker('Installing Fail2ban');
            $lines = array_merge($lines, $this->ensurePackagesInstalled(
                ['fail2ban'],
                '[dply] fail2ban already installed; skipping package install.'
            ));
            $lines[] = 'systemctl enable --now fail2ban || true';
        }

        $lines = array_merge($lines, $this->roleHardening($role));
        $lines[] = $this->stepMarker('Finalizing server');
        $lines[] = 'ufw --force enable || true';
        $lines[] = 'echo "[dply] provision finished"';

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function roleApplication(string $web, string $php, string $database, string $cache, array $layout): array
    {
        $lines = [];
        $lines = array_merge($lines, $this->ufwSsh());
        $lines = array_merge($lines, $this->installWebserver($web));
        $lines = array_merge($lines, $this->installPhpIfNeeded($web, $php, $database));
        $lines = array_merge($lines, $this->installDatabaseIfNeeded($database));
        $lines = array_merge($lines, $this->installAppCache($cache));
        $lines = array_merge($lines, $this->maybeInstallSupervisor());
        $lines = array_merge($lines, $this->writeRenderedConfigs('application', $web, $php, $layout));

        if (config('server_provision.install_composer', true) && $php !== 'none') {
            $lines[] = $this->stepMarker('Installing Composer');
            $lines[] = $this->forceReinstall()
                ? 'curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer'
                : 'if command -v composer >/dev/null 2>&1; then echo "[dply] composer already installed; skipping installer."; else curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer; fi';
        }

        return $lines;
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
    private function roleWorker(string $php, string $database, array $layout): array
    {
        $lines = [];
        $lines = array_merge($lines, $this->ufwSsh());
        $lines = array_merge($lines, $this->installPhpIfNeeded('none', $php, $database));
        $lines = array_merge($lines, $this->installDatabaseIfNeeded($database));
        $lines = array_merge($lines, $this->maybeInstallSupervisor());
        $lines = array_merge($lines, $this->writeRenderedConfigs('worker', 'none', $php, $layout));

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function roleDatabase(string $database, array $layout): array
    {
        $lines = [];
        $lines = array_merge($lines, $this->ufwSsh());
        $lines = array_merge($lines, $this->installDatabaseIfNeeded($database));
        $lines[] = 'ufw deny 3306/tcp || true';
        $lines[] = 'ufw deny 5432/tcp || true';
        $lines = array_merge($lines, $this->writeRenderedConfigs('database', 'none', 'none', $layout));

        return $lines;
    }

    /** @return list<string> */
    private function roleRedis(): array
    {
        $lines = $this->ufwSsh();
        $lines = array_merge($lines, $this->ensurePackagesInstalled(
            ['redis-server'],
            '[dply] redis-server already installed; skipping package install.'
        ));
        $lines[] = $this->writeFileWithRollback('/etc/redis/redis.conf', "bind 127.0.0.1 -::1\nprotected-mode yes\nmaxmemory 256mb\nmaxmemory-policy allkeys-lru\n");
        $lines[] = 'systemctl enable --now redis-server';

        return $lines;
    }

    /** @return list<string> */
    private function roleValkey(): array
    {
        $lines = $this->ufwSsh();
        $lines[] = 'if dpkg -s valkey-server >/dev/null 2>&1 || dpkg -s valkey >/dev/null 2>&1; then echo "[dply] valkey already installed; skipping package install."; else apt-get install -y --no-install-recommends valkey-server || apt-get install -y --no-install-recommends valkey; fi';
        $lines[] = $this->writeFileWithRollback('/etc/valkey/valkey.conf', "bind 127.0.0.1 ::1\nprotected-mode yes\nmaxmemory 256mb\nmaxmemory-policy allkeys-lru\n");
        $lines[] = 'systemctl enable --now valkey-server 2>/dev/null || systemctl enable --now valkey 2>/dev/null || true';

        return $lines;
    }

    /** @return list<string> */
    private function roleLoadBalancer(array $layout): array
    {
        $lines = $this->ufwSsh();
        $lines = array_merge($lines, $this->ensurePackagesInstalled(
            ['haproxy', 'certbot'],
            '[dply] haproxy and certbot already installed; skipping package install.'
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
    private function maybeInstallSupervisor(): array
    {
        if (! config('server_provision.install_supervisor_on_provision', false)) {
            return [];
        }

        return [
            $this->stepMarker('Installing Supervisor'),
            ...$this->ensurePackagesInstalled(
                ['supervisor'],
                '[dply] supervisor already installed; skipping package install.'
            ),
            'systemctl enable --now supervisor',
        ];
    }

    /** @return list<string> */
    private function ufwSsh(): array
    {
        return $this->withStep('Configuring firewall', [
            'ufw allow OpenSSH',
        ]);
    }

    /**
     * @return list<string>
     */
    private function installAppCache(string $cache): array
    {
        if ($cache === 'valkey') {
            return $this->withStep('Installing Valkey', [
                'if dpkg -s valkey-server >/dev/null 2>&1 || dpkg -s valkey >/dev/null 2>&1; then echo "[dply] valkey already installed; skipping package install."; else apt-get install -y --no-install-recommends valkey-server || apt-get install -y --no-install-recommends valkey; fi',
                $this->writeFileWithRollback('/etc/valkey/valkey.conf', "bind 127.0.0.1 ::1\nmaxmemory 256mb\nmaxmemory-policy allkeys-lru\n"),
                'systemctl enable --now valkey-server 2>/dev/null || systemctl enable --now valkey 2>/dev/null || true',
            ]);
        }

        return $this->withStep('Installing Redis', [
            ...$this->ensurePackagesInstalled(
                ['redis-server'],
                '[dply] redis-server already installed; skipping package install.'
            ),
            $this->writeFileWithRollback('/etc/redis/redis.conf', "bind 127.0.0.1 -::1\nmaxmemory 256mb\nmaxmemory-policy allkeys-lru\n"),
            'systemctl enable --now redis-server',
        ]);
    }

    /**
     * @return list<string>
     */
    private function installWebserver(string $web): array
    {
        if ($web === 'none') {
            return [];
        }

        $lines = [];

        if ($web === 'nginx') {
            $lines[] = $this->stepMarker('Installing webserver');
            $lines = array_merge($lines, $this->ensurePackagesInstalled(
                ['nginx'],
                '[dply] nginx already installed; skipping package install.'
            ));
            $lines[] = 'ufw allow "Nginx Full"';
            $lines[] = 'systemctl enable --now nginx';
            $lines = array_merge($lines, $this->certbotForWeb($web));
        } elseif ($web === 'apache') {
            $lines[] = $this->stepMarker('Installing webserver');
            $lines = array_merge($lines, $this->ensurePackagesInstalled(
                ['apache2'],
                '[dply] apache2 already installed; skipping package install.'
            ));
            $lines[] = 'ufw allow "Apache Full"';
            $lines[] = 'systemctl enable --now apache2';
            $lines = array_merge($lines, $this->certbotForWeb($web));
        } elseif ($web === 'openlitespeed') {
            $lines[] = $this->stepMarker('Installing webserver');
            $lines[] = 'wget -qO - https://repo.litespeed.sh | bash';
            $lines[] = 'apt-get update -y';
            $lines[] = 'apt-get install -y --no-install-recommends openlitespeed';
            $lines[] = 'ufw allow 80/tcp';
            $lines[] = 'ufw allow 443/tcp';
            $lines[] = '/usr/local/lsws/bin/lswsctrl start || true';
        } elseif ($web === 'caddy') {
            $lines[] = $this->stepMarker('Installing webserver');
            $lines[] = 'install -d /usr/share/keyrings';
            $lines[] = 'curl -fsSL https://dl.cloudsmith.io/public/caddy/stable/gpg.key | gpg --batch --yes --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg';
            $lines[] = 'curl -fsSL https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt | tee /etc/apt/sources.list.d/caddy-stable.list';
            $lines[] = 'apt-get update -y';
            $lines[] = 'apt-get install -y --no-install-recommends caddy';
            $lines[] = 'ufw allow 80/tcp';
            $lines[] = 'ufw allow 443/tcp';
            $lines[] = 'systemctl enable --now caddy';
        } elseif ($web === 'traefik') {
            $lines[] = $this->stepMarker('Installing webserver');
            $lines[] = 'apt-get install -y --no-install-recommends traefik caddy';
            $lines[] = 'install -d -m 0755 /etc/traefik/dynamic /etc/caddy/sites-enabled /var/log/traefik';
            $lines[] = $this->writeFileWithRollback('/etc/traefik/traefik.yml', "entryPoints:\n  web:\n    address: \":80\"\nproviders:\n  file:\n    directory: \"/etc/traefik/dynamic\"\n    watch: true\nlog:\n  filePath: \"/var/log/traefik/traefik.log\"\naccessLog:\n  filePath: \"/var/log/traefik/access.log\"\n");
            $lines[] = 'ufw allow 80/tcp';
            $lines[] = 'ufw allow 443/tcp';
            $lines[] = 'systemctl enable --now caddy';
            $lines[] = 'systemctl enable --now traefik';
        }

        return $lines;
    }

    /** @return list<string> */
    private function certbotForWeb(string $web): array
    {
        if ($web === 'nginx') {
            return $this->ensurePackagesInstalled(
                ['certbot', 'python3-certbot-nginx'],
                '[dply] nginx certbot packages already installed; skipping package install.'
            );
        }
        if ($web === 'apache') {
            return $this->ensurePackagesInstalled(
                ['certbot', 'python3-certbot-apache'],
                '[dply] apache certbot packages already installed; skipping package install.'
            );
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function installPhpIfNeeded(string $web, string $php, string $database): array
    {
        if ($php === 'none') {
            return [];
        }

        $stem = $this->phpStem($php);
        $lines = [
            $this->stepMarker('Installing PHP '.$php),
            ...$this->ensureOndrejPhpRepository(),
        ];

        $pkgs = [
            $stem.'-cli',
            $stem.'-fpm',
            $stem.'-common',
            $stem.'-mbstring',
            $stem.'-xml',
            $stem.'-curl',
            $stem.'-zip',
            $stem.'-intl',
            $stem.'-bcmath',
            $stem.'-opcache',
        ];

        if (str_starts_with($database, 'postgres')) {
            $pkgs[] = $stem.'-pgsql';
        } elseif (in_array($database, ['mysql84', 'mysql80', 'mysql57', 'mariadb114', 'mariadb11', 'mariadb1011'], true)) {
            $pkgs[] = $stem.'-mysql';
        } elseif ($database === 'sqlite3') {
            $pkgs[] = $stem.'-sqlite3';
        } else {
            $pkgs[] = $stem.'-mysql';
        }

        $lines = array_merge($lines, $this->ensurePackagesInstalled(
            $pkgs,
            '[dply] PHP packages already installed; skipping package install.'
        ));

        $lines = array_merge($lines, $this->wireWebserverToPhp($web, $php));

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function wireWebserverToPhp(string $web, string $php): array
    {
        if ($web === 'none' || $php === 'none') {
            return [];
        }

        if ($web === 'apache') {
            $stem = $this->phpStem($php);

            return [
                'apt-get install -y --no-install-recommends libapache2-mod-'.$stem,
                'a2enmod '.$stem,
                'systemctl reload apache2',
            ];
        }

        if ($web === 'openlitespeed') {
            return [
                'apt-get install -y --no-install-recommends lsphp'.str_replace('.', '', $php).' lsphp'.str_replace('.', '', $php).'-mysql lsphp'.str_replace('.', '', $php).'-pgsql || true',
                '/usr/local/lsws/bin/lswsctrl restart || true',
            ];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function installDatabaseIfNeeded(string $database): array
    {
        if ($database === 'none') {
            return [];
        }

        if (str_starts_with($database, 'postgres')) {
            return $this->installPostgresql($database);
        }

        if (str_starts_with($database, 'mysql') || $database === 'sqlite3') {
            if ($database === 'sqlite3') {
                return $this->withStep('Installing SQLite', ['apt-get install -y --no-install-recommends sqlite3 libsqlite3-0']);
            }

            return $this->withStep('Installing MySQL', [
                ...$this->ensurePackagesInstalled(
                    ['mysql-server'],
                    '[dply] mysql-server already installed; skipping package install.'
                ),
                $this->writeFileWithRollback('/etc/mysql/mysql.conf.d/dply.cnf', "[mysqld]\nbind-address = 127.0.0.1\nmax_connections = 200\ninnodb_buffer_pool_size = 256M\n"),
                'systemctl enable --now mysql',
                'systemctl restart mysql || true',
                'echo "[dply] MySQL variants (5.7/8.0/8.4) use distro mysql-server package where applicable; pin versions in follow-up automation if required."',
            ]);
        }

        if (str_starts_with($database, 'mariadb')) {
            return $this->withStep('Installing MariaDB', [
                ...$this->ensurePackagesInstalled(
                    ['mariadb-server'],
                    '[dply] mariadb-server already installed; skipping package install.'
                ),
                $this->writeFileWithRollback('/etc/mysql/mariadb.conf.d/99-dply.cnf', "[mysqld]\nbind-address = 127.0.0.1\nmax_connections = 200\ninnodb_buffer_pool_size = 256M\n"),
                'systemctl enable --now mariadb',
                'systemctl restart mariadb || true',
            ]);
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function installPostgresql(string $database): array
    {
        $ver = match ($database) {
            'postgres14' => '14',
            'postgres15' => '15',
            'postgres16' => '16',
            'postgres17' => '17',
            'postgres18' => '18',
            default => '16',
        };

        return [
            $this->stepMarker('Installing PostgreSQL'),
            'install -d /usr/share/postgresql-common/pgdg',
            'curl -fsSL -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc https://www.postgresql.org/media/keys/ACCC4CF8.asc',
            'chmod 644 /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc',
            '. /etc/os-release && echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt ${VERSION_CODENAME}-pgdg main" > /etc/apt/sources.list.d/pgdg.list',
            'apt-get update -y',
            ...$this->ensurePackagesInstalled(
                ['postgresql-'.$ver],
                '[dply] postgresql-'.$ver.' already installed; skipping package install.'
            ),
            $this->writeFileWithRollback('/etc/postgresql/'.$ver.'/main/conf.d/99-dply.conf', "listen_addresses = '127.0.0.1'\nshared_buffers = '256MB'\nmax_connections = 200\n"),
            'systemctl enable --now postgresql',
            'systemctl restart postgresql || true',
        ];
    }

    private function phpStem(string $phpVersionId): string
    {
        if (! preg_match('/^(\d+)\.(\d+)$/', $phpVersionId, $m)) {
            return 'php8.3';
        }

        return 'php'.$m[1].'.'.$m[2];
    }

    /**
     * @return array{root:string,app:string,releases:string,current:string,shared:string,logs:string,tmp:string,bin:string}
     */
    private function deployLayout(Server $server): array
    {
        return (new ServerDeployLayoutBuilder)->build($server);
    }

    /**
     * @param  array{root:string,app:string,releases:string,current:string,shared:string,logs:string,tmp:string,bin:string}  $layout
     * @return list<string>
     */
    private function createDeployLayout(array $layout): array
    {
        return $this->withStep('Preparing deploy layout', [
            'install -d -m 0755 '.implode(' ', array_map('escapeshellarg', [
                $layout['root'],
                $layout['app'],
                $layout['releases'],
                $layout['shared'],
                $layout['logs'],
                $layout['tmp'],
            ])),
            'ln -sfn '.escapeshellarg($layout['app']).' '.escapeshellarg($layout['current']),
            'chown -R dply:dply '.escapeshellarg($layout['root']).' || true',
            'cat > /usr/local/bin/dply-prepare-layout <<\'EOF\'
#!/bin/bash
set -euo pipefail
install -d -m 0755 '.trim($layout['releases']).' '.trim($layout['shared']).' '.trim($layout['logs']).' '.trim($layout['tmp']).'
chown -R dply:dply '.trim($layout['root']).'
EOF',
            'chmod 755 /usr/local/bin/dply-prepare-layout',
            $this->writeFileWithRollback('/etc/systemd/system/dply-prepare-layout.service', (new SystemdUnitFileBuilder)->buildDeployPrepareUnit()),
            'systemctl daemon-reload',
            'systemctl enable dply-prepare-layout.service',
            '/usr/local/bin/dply-prepare-layout',
        ]);
    }

    /**
     * @param  array{root:string,app:string,releases:string,current:string,shared:string,logs:string,tmp:string,bin:string}  $layout
     * @return array<string, array{label:string,path:string,content:string}>
     */
    private function renderedConfigs(string $role, string $web, string $php, array $layout): array
    {
        $configs = [];
        $phpSocket = $php !== 'none' ? '/run/php/'.$this->phpStem($php).'-fpm.sock' : null;

        if ($web === 'nginx') {
            $configs['nginx-starter'] = [
                'label' => 'Nginx starter site',
                'path' => '/etc/nginx/sites-available/dply',
                'content' => <<<NGINX
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    root {$layout['current']}/public;
    index index.php index.html;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:{$phpSocket};
    }
}
NGINX,
            ];
        }

        if ($web === 'caddy') {
            $configs['caddy-starter'] = [
                'label' => 'Caddy starter site',
                'path' => '/etc/caddy/Caddyfile',
                'content' => (new CaddySiteConfigBuilder)->build($layout['current'].'/public', $phpSocket),
            ];
        }

        if ($web === 'apache') {
            $configs['apache-starter'] = [
                'label' => 'Apache starter site',
                'path' => '/etc/apache2/sites-available/dply.conf',
                'content' => <<<APACHE
<VirtualHost *:80>
    ServerName localhost
    DocumentRoot {$layout['current']}/public
    <Directory {$layout['current']}/public>
        AllowOverride All
        Require all granted
        DirectoryIndex index.php index.html
        FallbackResource /index.php
    </Directory>
    <FilesMatch \.php$>
        SetHandler "proxy:unix:{$phpSocket}|fcgi://localhost/"
    </FilesMatch>
</VirtualHost>
APACHE,
            ];
        }

        if ($web === 'openlitespeed') {
            $configs['openlitespeed-starter'] = [
                'label' => 'OpenLiteSpeed starter site',
                'path' => '/usr/local/lsws/conf/vhosts/dply/vhconf.conf',
                'content' => "docRoot {$layout['current']}/public/\nvhDomain localhost\nindex  {\n  useServer 0\n  indexFiles index.php, index.html\n}\n",
            ];
        }

        if ($web === 'traefik') {
            $configs['traefik-starter'] = [
                'label' => 'Traefik starter config',
                'path' => '/etc/traefik/traefik.yml',
                'content' => "entryPoints:\n  web:\n    address: \":80\"\nproviders:\n  file:\n    directory: \"/etc/traefik/dynamic\"\n    watch: true\n",
            ];
        }

        if (in_array($role, ['worker', 'plain', 'application'], true)) {
            $configs['supervisor-default'] = [
                'label' => 'Supervisor default worker',
                'path' => '/etc/supervisor/conf.d/dply-default-worker.conf',
                'content' => "[program:dply-default-worker]\ncommand=/bin/true\nautostart=false\nautorestart=false\nstdout_logfile={$layout['logs']}/worker.log\n",
            ];
        }

        if ($role === 'load_balancer') {
            $configs['haproxy-default'] = [
                'label' => 'HAProxy starter config',
                'path' => '/etc/haproxy/haproxy.cfg',
                'content' => (new HaproxyConfigBuilder)->build(),
            ];
        }

        if ($role === 'docker') {
            $configs['systemd-docker-note'] = [
                'label' => 'Docker host systemd unit',
                'path' => '/etc/systemd/system/dply-prepare-layout.service',
                'content' => (new SystemdUnitFileBuilder)->buildDeployPrepareUnit(),
            ];
        }

        return $configs;
    }

    /**
     * @param  array{root:string,app:string,releases:string,current:string,shared:string,logs:string,tmp:string,bin:string}  $layout
     * @return list<string>
     */
    private function writeRenderedConfigs(string $role, string $web, string $php, array $layout): array
    {
        $lines = [];

        foreach ($this->renderedConfigs($role, $web, $php, $layout) as $config) {
            $lines[] = $this->stepMarker('Writing '.$config['label']);
            $lines[] = $this->writeFileWithRollback($config['path'], $config['content']);

            if ($config['path'] === '/etc/nginx/sites-available/dply') {
                $lines[] = 'ln -sfn /etc/nginx/sites-available/dply /etc/nginx/sites-enabled/dply';
                $lines[] = 'rm -f /etc/nginx/sites-enabled/default';
                $lines[] = 'nginx -t && systemctl reload nginx';
            }

            if ($config['path'] === '/etc/caddy/Caddyfile') {
                $lines[] = 'systemctl reload caddy || systemctl restart caddy';
            }

            if ($config['path'] === '/etc/apache2/sites-available/dply.conf') {
                $lines[] = 'a2enmod proxy proxy_fcgi rewrite headers >/dev/null 2>&1 || true';
                $lines[] = 'a2ensite dply.conf';
                $lines[] = 'apachectl configtest';
                $lines[] = 'systemctl reload apache2 || service apache2 reload';
            }

            if ($config['path'] === '/usr/local/lsws/conf/vhosts/dply/vhconf.conf') {
                $lines[] = 'install -d -m 0755 /usr/local/lsws/conf/vhosts/dply';
                $lines[] = '/usr/local/lsws/bin/lswsctrl restart || true';
            }

            if ($config['path'] === '/etc/traefik/traefik.yml') {
                $lines[] = 'install -d -m 0755 /etc/traefik/dynamic /var/log/traefik';
                $lines[] = 'systemctl restart traefik';
            }

            if ($config['path'] === '/etc/haproxy/haproxy.cfg') {
                $lines[] = 'haproxy -c -f /etc/haproxy/haproxy.cfg';
                $lines[] = 'systemctl restart haproxy';
            }
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function verificationCommands(string $role, string $web, string $php, string $database, string $cache): array
    {
        $lines = [$this->stepMarker('Running verification checks')];

        foreach ($this->verificationLabels($role, $web, $php, $database, $cache) as $label => $command) {
            $lines[] = 'if '.$command.' >/dev/null 2>&1; then echo '.escapeshellarg(self::VERIFY_PREFIX.$label.' :: ok :: Check passed').'; else echo '.escapeshellarg(self::VERIFY_PREFIX.$label.' :: failed :: Check failed').'; fi';
        }

        return $lines;
    }

    /**
     * @return array<string, string>
     */
    private function verificationLabels(string $role, string $web, string $php, string $database, string $cache): array
    {
        $checks = ['ufw' => 'ufw status'];

        if ($php !== 'none') {
            $checks['php'] = 'php -v';
            $checks['php-fpm'] = 'systemctl is-active '.$this->phpStem($php).'-fpm';
        }

        if ($web === 'nginx') {
            $checks['nginx'] = 'nginx -t';
        } elseif ($web === 'caddy') {
            $checks['caddy'] = 'caddy validate --config /etc/caddy/Caddyfile';
        } elseif ($web === 'apache') {
            $checks['apache'] = 'apachectl configtest';
        } elseif ($web === 'openlitespeed') {
            $checks['openlitespeed'] = '/usr/local/lsws/bin/lswsctrl status';
        } elseif ($web === 'traefik') {
            $checks['traefik'] = 'systemctl is-active traefik';
            $checks['caddy-backend'] = 'caddy validate --config /etc/caddy/Caddyfile';
        }

        if (str_starts_with($database, 'postgres')) {
            $checks['postgresql'] = 'systemctl is-active postgresql';
        } elseif ($database !== 'none' && $database !== 'sqlite3') {
            $checks['mysql'] = 'systemctl is-active mysql || systemctl is-active mariadb';
        }

        if ($cache === 'redis') {
            $checks['redis'] = 'redis-cli ping';
        } elseif ($cache === 'valkey') {
            $checks['valkey'] = 'valkey-cli ping || redis-cli ping';
        }

        if ($role === 'load_balancer') {
            $checks['haproxy'] = 'haproxy -c -f /etc/haproxy/haproxy.cfg';
        }

        if ($role === 'docker') {
            $checks['docker'] = 'docker --version';
            $checks['docker-daemon'] = 'systemctl is-active docker';
        }

        return $checks;
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

    private function writeFileWithRollback(string $path, string $content): string
    {
        $encodedPath = base64_encode($path);
        $encodedContent = base64_encode($content);

        return 'dply_write_file '.escapeshellarg($encodedPath).' '.escapeshellarg($encodedContent);
    }

    private function isAllowed(string $section, string $id): bool
    {
        $rows = config('server_provision_options.'.$section, []);

        return collect(is_array($rows) ? $rows : [])->pluck('id')->contains($id);
    }

    private function coalesceId(string $section, mixed $value, string $fallback): string
    {
        if (is_string($value) && $this->isAllowed($section, $value)) {
            return $value;
        }

        return $this->isAllowed($section, $fallback) ? $fallback : (string) $value;
    }

    private function stepMarker(string $label): string
    {
        return 'echo '.escapeshellarg(self::STEP_PREFIX.$label);
    }

    /**
     * @param  list<string>  $commands
     * @return list<string>
     */
    private function withStep(string $label, array $commands): array
    {
        return [
            $this->stepMarker($label),
            ...$commands,
        ];
    }

    /**
     * @param  list<string>  $packages
     * @return list<string>
     */
    private function ensurePackagesInstalled(array $packages, string $alreadyInstalledMessage): array
    {
        $packages = array_values(array_filter($packages, fn (string $package): bool => trim($package) !== ''));
        if ($packages === []) {
            return [];
        }

        $checks = array_map(
            fn (string $package): string => 'dpkg -s '.escapeshellarg($package).' >/dev/null 2>&1',
            $packages,
        );

        if ($this->forceReinstall()) {
            return [
                'apt-get install -y --no-install-recommends '.implode(' ', $packages),
            ];
        }

        return [
            'if '.implode(' && ', $checks).'; then echo '.escapeshellarg($alreadyInstalledMessage).'; else apt-get install -y --no-install-recommends '.implode(' ', $packages).'; fi',
        ];
    }

    /**
     * @return list<string>
     */
    private function ensureOndrejPhpRepository(): array
    {
        if ($this->forceReinstall()) {
            return [
                'timeout 120s add-apt-repository -y ppa:ondrej/php',
                'timeout 300s apt-get update -y',
            ];
        }

        return [
            'if grep -RqsE "ondrej-ubuntu-php|ppa\\.launchpadcontent\\.net/ondrej/php" /etc/apt/sources.list /etc/apt/sources.list.d; then echo "[dply] ondrej/php repository already installed; skipping repository setup."; else timeout 120s add-apt-repository -y ppa:ondrej/php && timeout 300s apt-get update -y; fi',
        ];
    }

    private function forceReinstall(): bool
    {
        return (bool) config('server_provision.force_reinstall', false);
    }
}
