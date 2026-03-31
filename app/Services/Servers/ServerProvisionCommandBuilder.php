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

        $lines = [];
        $lines[] = 'echo "[dply] provision start role='.$role.' web='.$web.' php='.$php.' db='.$database.' cache='.$cache.'"';

        $lines = array_merge($lines, $this->dplyDeployUserBootstrap($server));
        $lines = array_merge($lines, $this->bootstrap());
        $lines = array_merge($lines, match ($role) {
            'application' => $this->roleApplication($web, $php, $database, $cache),
            'docker' => $this->roleDocker($web, $php, $database, $cache),
            'worker' => $this->roleWorker($php, $database),
            'database' => $this->roleDatabase($database),
            'redis' => $this->roleRedis(),
            'valkey' => $this->roleValkey(),
            'load_balancer' => $this->roleLoadBalancer(),
            'plain' => $this->rolePlain(),
            default => [],
        });

        $lines = array_merge($lines, $this->finalize());

        return $lines;
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
            'apt-get install -y --no-install-recommends ca-certificates curl gnupg lsb-release software-properties-common ufw unattended-upgrades',
        ];
    }

    /**
     * @return list<string>
     */
    private function finalize(): array
    {
        $lines = [];

        if (config('server_provision.install_fail2ban', true)) {
            $lines[] = $this->stepMarker('Installing Fail2ban');
            $lines[] = 'apt-get install -y --no-install-recommends fail2ban';
            $lines[] = 'systemctl enable --now fail2ban || true';
        }

        $lines[] = $this->stepMarker('Finalizing server');
        $lines[] = 'ufw --force enable || true';
        $lines[] = 'echo "[dply] provision finished"';

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function roleApplication(string $web, string $php, string $database, string $cache): array
    {
        $lines = [];
        $lines = array_merge($lines, $this->ufwSsh());
        $lines = array_merge($lines, $this->installWebserver($web));
        $lines = array_merge($lines, $this->installPhpIfNeeded($web, $php, $database));
        $lines = array_merge($lines, $this->installDatabaseIfNeeded($database));
        $lines = array_merge($lines, $this->installAppCache($cache));
        $lines = array_merge($lines, $this->maybeInstallSupervisor());

        if (config('server_provision.install_composer', true) && $php !== 'none') {
            $lines[] = $this->stepMarker('Installing Composer');
            $lines[] = 'curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer';
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function roleDocker(string $web, string $php, string $database, string $cache): array
    {
        return array_merge($this->withStep('Installing Docker', [
            'apt-get install -y --no-install-recommends docker.io docker-compose-v2',
            'systemctl enable --now docker',
        ]), $this->roleApplication($web, $php, $database, $cache));
    }

    /**
     * @return list<string>
     */
    private function roleWorker(string $php, string $database): array
    {
        $lines = [];
        $lines = array_merge($lines, $this->ufwSsh());
        $lines = array_merge($lines, $this->installPhpIfNeeded('none', $php, $database));
        $lines = array_merge($lines, $this->installDatabaseIfNeeded($database));
        $lines = array_merge($lines, $this->maybeInstallSupervisor());

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function roleDatabase(string $database): array
    {
        $lines = [];
        $lines = array_merge($lines, $this->ufwSsh());
        $lines = array_merge($lines, $this->installDatabaseIfNeeded($database));

        return $lines;
    }

    /** @return list<string> */
    private function roleRedis(): array
    {
        $lines = $this->ufwSsh();
        $lines[] = 'apt-get install -y --no-install-recommends redis-server';
        $lines[] = 'systemctl enable --now redis-server';

        return $lines;
    }

    /** @return list<string> */
    private function roleValkey(): array
    {
        $lines = $this->ufwSsh();
        $lines[] = 'apt-get install -y --no-install-recommends valkey-server || apt-get install -y --no-install-recommends valkey';
        $lines[] = 'systemctl enable --now valkey-server 2>/dev/null || systemctl enable --now valkey 2>/dev/null || true';

        return $lines;
    }

    /** @return list<string> */
    private function roleLoadBalancer(): array
    {
        $lines = $this->ufwSsh();
        $lines[] = 'apt-get install -y --no-install-recommends haproxy certbot';
        $lines[] = 'systemctl enable --now haproxy';
        $lines[] = 'ufw allow 80/tcp';
        $lines[] = 'ufw allow 443/tcp';

        return $lines;
    }

    /** @return list<string> */
    private function rolePlain(): array
    {
        $lines = $this->ufwSsh();
        $lines = array_merge($lines, $this->maybeInstallSupervisor());

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
            'apt-get install -y --no-install-recommends supervisor',
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
                'apt-get install -y --no-install-recommends valkey-server || apt-get install -y --no-install-recommends valkey',
                'systemctl enable --now valkey-server 2>/dev/null || systemctl enable --now valkey 2>/dev/null || true',
            ]);
        }

        return $this->withStep('Installing Redis', [
            'apt-get install -y --no-install-recommends redis-server',
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
            $lines[] = 'apt-get install -y --no-install-recommends nginx';
            $lines[] = 'ufw allow "Nginx Full"';
            $lines[] = 'systemctl enable --now nginx';
            $lines = array_merge($lines, $this->certbotForWeb($web));
        } elseif ($web === 'apache') {
            $lines[] = $this->stepMarker('Installing webserver');
            $lines[] = 'apt-get install -y --no-install-recommends apache2';
            $lines[] = 'ufw allow "Apache Full"';
            $lines[] = 'systemctl enable --now apache2';
            $lines = array_merge($lines, $this->certbotForWeb($web));
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
        }

        return $lines;
    }

    /** @return list<string> */
    private function certbotForWeb(string $web): array
    {
        if ($web === 'nginx') {
            return ['apt-get install -y --no-install-recommends certbot python3-certbot-nginx'];
        }
        if ($web === 'apache') {
            return ['apt-get install -y --no-install-recommends certbot python3-certbot-apache'];
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
            'add-apt-repository -y ppa:ondrej/php',
            'apt-get update -y',
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

        $lines[] = 'apt-get install -y --no-install-recommends '.implode(' ', $pkgs);

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

        $stem = $this->phpStem($php);
        $sock = '/run/php/'.$stem.'-fpm.sock';

        if ($web === 'nginx') {
            return [
                'cat > /etc/nginx/sites-available/dply <<\'NGINXEOF\'
server {
	listen 80 default_server;
	listen [::]:80 default_server;
	root /var/www/html;
	index index.php index.html;
	location / {
		try_files $uri $uri/ =404;
	}
	location ~ \.php$ {
		include snippets/fastcgi-php.conf;
		fastcgi_pass unix:'.$sock.';
	}
}
NGINXEOF',
                'ln -sf /etc/nginx/sites-available/dply /etc/nginx/sites-enabled/dply',
                'rm -f /etc/nginx/sites-enabled/default',
                'nginx -t && systemctl reload nginx',
            ];
        }

        if ($web === 'apache') {
            return [
                'apt-get install -y --no-install-recommends libapache2-mod-'.$stem,
                'a2enmod '.$stem,
                'systemctl reload apache2',
            ];
        }

        if ($web === 'caddy') {
            return [
                'cat > /etc/caddy/Caddyfile <<\'EOF\'
:80 {
	root * /var/www/html
	php_fastcgi unix//'.$sock.'
	file_server
}
EOF',
                'systemctl reload caddy || systemctl restart caddy',
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
                'apt-get install -y --no-install-recommends mysql-server',
                'systemctl enable --now mysql',
                'echo "[dply] MySQL variants (5.7/8.0/8.4) use distro mysql-server package where applicable; pin versions in follow-up automation if required."',
            ]);
        }

        if (str_starts_with($database, 'mariadb')) {
            return $this->withStep('Installing MariaDB', [
                'apt-get install -y --no-install-recommends mariadb-server',
                'systemctl enable --now mariadb',
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
            'apt-get install -y --no-install-recommends postgresql-'.$ver,
            'systemctl enable --now postgresql',
        ];
    }

    private function phpStem(string $phpVersionId): string
    {
        if (! preg_match('/^(\d+)\.(\d+)$/', $phpVersionId, $m)) {
            return 'php8.3';
        }

        return 'php'.$m[1].'.'.$m[2];
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
}
