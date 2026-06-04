<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Error remediations
|--------------------------------------------------------------------------
| A catalog of recognized failure signatures → remediations. When an operation
| fails (a deploy step, a console action), its error text is matched against
| these `signature` regexes; a match surfaces a Fix panel where the failure is
| shown (deploy console, Errors view) with one or more actions.
|
| Each remediation: { signature, title, explanation, actions[] }.
| Each action: {
|   key, label, recommended?, auto_safe?,
|   script?  — bash run over SSH as root via ApplyRemediationJob, or
|   handler? — a class implementing RemediationActionInterface (for preflight /
|              reuse of an existing Insight fix). One of script|handler required.
| }
|
| `auto_safe` marks an action idempotent + non-destructive + high-confidence, so
| it MAY be applied automatically when an org opts into auto-remediation. Ask-only
| remediations (disk-full, migration conflicts) carry no auto_safe action.
*/

return [
    'php_ext_redis_missing' => [
        'signature' => '/Class ["\']Redis["\'] not found|PhpRedisConnector\.php/i',
        'title' => 'PHP Redis extension (phpredis) is missing',
        'explanation' => 'The app is configured to use the phpredis client (REDIS_CLIENT=phpredis) but the php-redis extension is not installed on this server, so Horizon, the queue, and the cache can’t connect to Redis.',
        'actions' => [
            [
                'key' => 'install_phpredis',
                'label' => 'Install php-redis and reload PHP-FPM',
                'recommended' => true,
                'auto_safe' => true,
                'script' => <<<'BASH'
set -e
export DEBIAN_FRONTEND=noninteractive
PHPVER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)
apt-get update -y
if [ -n "$PHPVER" ]; then
  apt-get install -y "php${PHPVER}-redis" || apt-get install -y php-redis
  phpenmod -v "$PHPVER" redis 2>/dev/null || true
  systemctl reload "php${PHPVER}-fpm" 2>/dev/null || systemctl restart "php${PHPVER}-fpm" 2>/dev/null || true
else
  apt-get install -y php-redis
fi
systemctl reload php-fpm 2>/dev/null || true
php -m | grep -qi redis && echo "phpredis installed and loaded." || { echo "phpredis still not loaded" >&2; exit 1; }
BASH,
            ],
        ],
    ],

    'php_pdo_driver_missing' => [
        'signature' => '/could not find driver|PDOException.*(driver|pdo_(mysql|pgsql))/i',
        'title' => 'PHP database driver is missing',
        'explanation' => 'A PDO database driver (pdo_mysql / pdo_pgsql) isn’t installed for the active PHP version, so the app can’t open a database connection.',
        'actions' => [
            [
                'key' => 'install_pdo_mysql',
                'label' => 'Install pdo_mysql + reload PHP-FPM',
                'recommended' => true,
                'auto_safe' => true,
                'script' => <<<'BASH'
set -e
export DEBIAN_FRONTEND=noninteractive
PHPVER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)
apt-get update -y
apt-get install -y "php${PHPVER}-mysql" || apt-get install -y php-mysql
systemctl reload "php${PHPVER}-fpm" 2>/dev/null || systemctl reload php-fpm 2>/dev/null || true
echo "pdo_mysql installed."
BASH,
            ],
            [
                'key' => 'install_pdo_pgsql',
                'label' => 'Install pdo_pgsql + reload PHP-FPM',
                'auto_safe' => false,
                'script' => <<<'BASH'
set -e
export DEBIAN_FRONTEND=noninteractive
PHPVER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)
apt-get update -y
apt-get install -y "php${PHPVER}-pgsql" || apt-get install -y php-pgsql
systemctl reload "php${PHPVER}-fpm" 2>/dev/null || systemctl reload php-fpm 2>/dev/null || true
echo "pdo_pgsql installed."
BASH,
            ],
        ],
    ],

    'disk_full' => [
        'signature' => '/No space left on device|ENOSPC/i',
        'title' => 'The server is out of disk space',
        'explanation' => 'A write failed because the disk is full. The quickest signal is what’s consuming it; growing the volume is the durable fix.',
        'actions' => [
            [
                'key' => 'show_disk',
                'label' => 'Show what’s using the disk',
                'recommended' => true,
                'auto_safe' => false,
                'script' => "df -h / && echo '---- largest under /home ----' && du -xh --max-depth=2 /home 2>/dev/null | sort -rh | head -20",
            ],
        ],
    ],
];
