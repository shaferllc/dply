<?php

declare(strict_types=1);
use App\Modules\Remediations\Services\Actions\RebuildWebserverConfigAction;

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
    // A "guided" remediation: instead of a one-click script, it routes the
    // operator into state-aware fix flows (attach a database / inject DB_*),
    // rendered inline under the failed step by the DeployDatabaseFix component.
    // The signature catches the whole "can't reach/authenticate to the DB"
    // family across both Postgres and MySQL — connection refused, host not
    // found, auth failed, and unknown-database — so the next failure isn't left
    // unrecognized just because it surfaced a different SQLSTATE than 08006.
    'database_connection_failed' => [
        'signature' => '/SQLSTATE\[08006\]|SQLSTATE\[08001\]|SQLSTATE\[08004\]|SQLSTATE\[08003\]|SQLSTATE\[28P01\]|SQLSTATE\[3D000\]|SQLSTATE\[HY000\]\s*\[2002\]|connection to server at .* failed|could not connect to server|could not translate host name|password authentication failed|Access denied for user|Unknown database|\[1045\]|\[1049\]|\[2002\]/i',
        'title' => 'Can’t connect to the database',
        'explanation' => 'A deploy step couldn’t reach the database. dply can diagnose why and walk you through attaching a database or injecting the connection details.',
        // No `actions` — `guided` flips this off the top script-panel; the
        // DeployDatabaseFix Livewire component renders the state-aware fixes
        // inline under the failed step instead.
        'guided' => true,
        'actions' => [],
    ],

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
export DEBIAN_FRONTEND=noninteractive
PHPVER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)
apt-get update -y || true

is_loaded() { php -m 2>/dev/null | grep -qi '^redis$'; }

# 1) apt — version-pinned, then generic. (apt-get inside `if` won't trip set -e.)
if ! is_loaded && [ -n "$PHPVER" ]; then apt-get install -y "php${PHPVER}-redis" 2>/dev/null || true; fi
if ! is_loaded; then apt-get install -y php-redis 2>/dev/null || true; fi

# 2) PECL fallback — when the apt package is missing or held by a version
#    conflict (e.g. a newer php-common Breaks the only php-redis in the repo),
#    build the extension from source so we don't depend on the apt package.
if ! is_loaded; then
  echo "apt could not install phpredis — building it from source via PECL…"
  apt-get install -y php-pear "php${PHPVER}-dev" autoconf build-essential pkg-config 2>/dev/null \
    || apt-get install -y php-pear php-dev autoconf build-essential pkg-config 2>/dev/null || true
  pecl channel-update pecl.php.net 2>/dev/null || true
  printf 'no\nno\nno\n' | pecl install -f redis 2>&1 | tail -8 || true
fi

# Enable the extension EXACTLY ONCE. Debian/sury: a single mods-available/redis.ini
# symlinked per-SAPI by phpenmod. Crucially, strip any stray `extension=redis`
# PECL appended to the real php.ini files — loading it twice makes PHP-FPM fail to
# start ("module 'redis' already loaded"), which shows as an nginx 502 on the site.
if [ -d "/etc/php/${PHPVER}/mods-available" ]; then
  printf '; phpredis (managed by dply remediation)\nextension=redis.so\n' > "/etc/php/${PHPVER}/mods-available/redis.ini"
  for sapi in cli fpm apache2; do
    INI="/etc/php/${PHPVER}/${sapi}/php.ini"
    [ -f "$INI" ] && sed -i -E '/^[[:space:]]*extension[[:space:]]*=[[:space:]]*"?redis(\.so)?"?[[:space:]]*$/d' "$INI"
  done
  phpenmod -v "$PHPVER" redis 2>/dev/null || true
else
  PHPINI=$(php -i 2>/dev/null | awk -F'=> ' '/Loaded Configuration File/ {print $2}' | tr -d ' ')
  if [ -n "$PHPINI" ] && [ -f "$PHPINI" ] && ! grep -qE '^[[:space:]]*extension[[:space:]]*=[[:space:]]*"?redis' "$PHPINI"; then
    printf 'extension=redis.so\n' >> "$PHPINI"
  fi
fi

# Restart (not reload) so a master that died from a double-load comes back clean.
systemctl restart "php${PHPVER}-fpm" 2>/dev/null || systemctl restart php-fpm 2>/dev/null || true

# Verify BOTH: the extension is loaded AND PHP-FPM is actually running — a loaded
# CLI extension is meaningless if FPM (what serves the site) is down with a 502.
fpm_ok=1
systemctl is-active --quiet "php${PHPVER}-fpm" 2>/dev/null || systemctl is-active --quiet php-fpm 2>/dev/null || fpm_ok=0
if is_loaded && [ "$fpm_ok" -eq 1 ]; then
  echo "phpredis installed and loaded for PHP ${PHPVER}; PHP-FPM is running."
else
  echo "phpredis was built but PHP-FPM is not healthy (often a duplicate extension line). Check 'systemctl status php${PHPVER}-fpm' and 'journalctl -u php${PHPVER}-fpm -n 50'." >&2
  exit 1
fi
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

    'webserver_vhost_missing' => [
        // The health-check diagnostics surface this when requests land on nginx's
        // default server instead of a site vhost — the deploy succeeds but the
        // site 502s. (See AtomicDeployHealthChecker's "falls through to the
        // default server" / `_default` probes.)
        'signature' => '/falls through to the default server|enabled vhost for [^\n]*: NONE|stat\(\) "[^"]*_default[^"]*" failed \(13/i',
        'title' => 'No nginx vhost is serving this site (502)',
        'explanation' => 'Requests are falling through to nginx’s default server, so there’s no vhost for this site’s hostname — the deploy succeeds but the site returns 502. Re-applying the webserver config regenerates and enables the vhost.',
        'actions' => [
            [
                'key' => 'rebuild_webserver',
                'label' => 'Rebuild webserver config (re-apply the nginx vhost)',
                'recommended' => true,
                'auto_safe' => true,
                'handler' => RebuildWebserverConfigAction::class,
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
