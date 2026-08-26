<?php

declare(strict_types=1);

use App\Modules\Remediations\Services\Actions\InstallPhpExtensionAction;
use App\Modules\Remediations\Services\Actions\RebuildWebserverConfigAction;
use App\Modules\Remediations\Services\Actions\UpgradePhpAction;
use App\Services\Servers\PhpRedisExtensionScripts;

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

    // Git host rejected dply's credentials during clone/fetch. Not an SSH-script
    // fix — the credential lives on the operator's dply account, so the single
    // action is a LINK to Settings → Source control where the token can be
    // replaced in place (see the `route` action key rendered by the panel).
    // "Repository not found" is included: GitHub answers that for private repos
    // when auth is bad/expired, indistinguishable from a genuinely wrong URL.
    'git_auth_failed' => [
        'signature' => '/Invalid username or token|Support for password authentication was removed|could not read Username for|fatal: Authentication failed|Authentication failed for|Permission denied \(publickey\)|remote: Repository not found|ERROR: Repository not found|HTTP Basic: Access denied/i',
        'title' => 'Git authentication failed — dply could not access the repository',
        'explanation' => 'The Git host rejected dply’s stored credentials for this repository. Most often the connected token has expired or been revoked (GitHub fine-grained tokens expire after 30 days by default); it can also mean the deploy key was removed or the account lost access to the repo. Replace the token — your own under Settings → Source control, or the organization’s shared token under Credentials — then re-deploy.',
        'actions' => [
            [
                'key' => 'open_source_control_settings',
                'label' => 'Update your Git credentials',
                'recommended' => true,
                'route' => 'profile.source-control',
            ],
            // The failing credential is often somebody else's, and the personal
            // page above then opens the WRONG account's settings. An org admin
            // can always fix it here by adding or replacing the shared token.
            [
                'key' => 'open_organization_credentials',
                'label' => 'Organization credentials',
                'route' => 'organizations.credentials',
                'org' => true,
                'params' => ['filter' => 'git'],
            ],
        ],
    ],

    // The configured ref is gone from the remote — renamed default branch
    // (master → main) being the classic. Also matches the deploy preflight's
    // "was not found on the remote repository" fast-fail message.
    'git_branch_missing' => [
        'signature' => '/Remote branch .+ not found in upstream|couldn\'?t find remote ref|Could not find remote branch|was not found on the remote repository/i',
        'title' => 'The configured branch no longer exists on the remote',
        'explanation' => 'The Git host is reachable and dply authenticated, but the branch (or tag) this site deploys from is gone — usually renamed (e.g. master → main) or deleted. Update the branch on the site’s repository settings, then re-deploy.',
        'actions' => [],
    ],

    'git_host_key_failed' => [
        'signature' => '/Host key verification failed/i',
        'title' => 'SSH host key verification failed during Git clone',
        'explanation' => 'The server refused the Git host’s SSH fingerprint. This happens when the host key changed (host migration) or a stale known_hosts entry conflicts. dply clones with accept-new, so a conflicting stale entry on the box is the usual cause — clearing the Git host from the deploy user’s ~/.ssh/known_hosts resolves it.',
        'actions' => [],
    ],

    'git_clone_dir_exists' => [
        'signature' => '/destination path .+ already exists and is not an empty directory/i',
        'title' => 'The clone target directory already has files in it',
        'explanation' => 'Git refuses to clone into a non-empty directory — usually leftovers from an earlier failed deploy or a provisioning placeholder. Re-deploying mints a fresh release directory for atomic sites; for simple sites, clear the site directory’s stray files first.',
        'actions' => [],
    ],

    'server_disk_full' => [
        'signature' => '/No space left on device|Disk quota exceeded/i',
        'title' => 'The server is out of disk space',
        'explanation' => 'The operation failed because the disk is full. Free space from the server’s Hygiene tab (old releases, logs, apt caches, orphaned images are the usual suspects), then re-run.',
        'actions' => [],
    ],

    // Network-shaped git failures: DNS, unreachable host, dropped transfers.
    // These are usually transient — the clone path retries them automatically;
    // reaching this remediation means the retries were exhausted.
    'git_network_unreachable' => [
        'signature' => '/Could not resolve host|Failed to connect to .+ port|The remote end hung up unexpectedly|early EOF|RPC failed/i',
        'title' => 'The Git host could not be reached from the server',
        'explanation' => 'The clone failed on network grounds (DNS, connectivity, or a dropped transfer), not credentials. dply already retried automatically. Check the server’s outbound connectivity / DNS and the Git host’s status page, then re-deploy.',
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
                'script' => implode("\n", [
                    <<<'BASH'
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

if [ -d "/etc/php/${PHPVER}/mods-available" ] && [ ! -f "/etc/php/${PHPVER}/mods-available/redis.ini" ]; then
  printf '; phpredis (managed by dply)\nextension=redis.so\n' > "/etc/php/${PHPVER}/mods-available/redis.ini"
fi
BASH,
                    PhpRedisExtensionScripts::dedupeFromDetectedCli(),
                    <<<'BASH'
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
                ]),
            ],
        ],
    ],

    'php_ext_redis_duplicate' => [
        'signature' => '/Module ["\']redis["\'] is already loaded/i',
        'title' => 'PHP is loading the redis extension twice',
        'explanation' => 'The redis module is enabled in more than one ini file (usually apt’s 20-redis.ini plus a leftover `extension=redis` from PECL in php.ini). PHP warns on every CLI/FPM start, and FPM can fail to boot. dply can keep a single mods-available copy and remove the extras.',
        'actions' => [
            [
                'key' => 'dedupe_phpredis',
                'label' => 'Remove duplicate redis.ini loads and reload PHP-FPM',
                'recommended' => true,
                'auto_safe' => true,
                'script' => implode("\n", [
                    PhpRedisExtensionScripts::dedupeFromDetectedCli(),
                    <<<'BASH'
PHPVER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)
systemctl restart "php${PHPVER}-fpm" 2>/dev/null || systemctl restart php-fpm 2>/dev/null || true
if php -v 2>&1 | grep -q 'already loaded'; then
  echo "A module is still reported as already loaded. Remaining redis directives:" >&2
  grep -RHnE '^[[:space:]]*extension[[:space:]]*=[[:space:]]*"?redis' /etc/php --include='*.ini' >&2 || true
  exit 1
fi
if ! php -m 2>/dev/null | grep -qi '^redis$'; then
  echo "Deduped redis inis but the extension is no longer loaded." >&2
  exit 1
fi
echo "phpredis now loads once for PHP ${PHPVER}."
BASH,
                ]),
            ],
        ],
    ],

    // Composer refused to install because the box's PHP is older than a
    // package requires (Laravel 13 / Symfony 8 → >=8.4.1 is the usual case).
    // Installing the catalog version and switching THIS SITE is the fix —
    // adding a pipeline step would not change the runtime. Deliberately not a
    // server-wide CLI default: SitePhpCliGuard already prefixes each site's
    // composer/artisan steps with its own /usr/bin/php<version>, so one site
    // needing 8.5 must not move a neighbour that needs 8.4.
    'php_version_too_low' => [
        'signature' => '/your php version\s+\([^)]+\) does not satisfy that requirement|requires php\s*(>=|>|\^|~)?\s*\d+\.\d+[^\n]*does not satisfy that requirement/i',
        'title' => 'This app needs a newer PHP than the server is running',
        'explanation' => 'Composer could not install dependencies because this site\'s PHP is older than a package requires. dply can install the needed version and switch this site onto it. Other sites on the server keep the version they are on — each site pins its own.',
        'actions' => [
            [
                'key' => 'upgrade_php',
                'label' => 'Upgrade PHP and switch this site',
                'recommended' => true,
                'auto_safe' => false,
                'handler' => UpgradePhpAction::class,
            ],
        ],
    ],

    // Ordered AFTER php_ext_redis_missing and php_pdo_driver_missing on purpose:
    // match() returns the first hit, and both of those own a symbol this
    // signature would otherwise swallow with a plain apt install — phpredis
    // needs its PECL fallback and duplicate-module handling, PDO drivers need
    // the driver-specific package.
    'php_ext_missing' => [
        'signature' => '/Class ["\'](?:Imagick|ImagickPixel|ImagickDraw|Memcached|MongoDB\\\\Driver\\\\Manager|ZipArchive|SoapClient|SoapServer|IntlDateFormatter|NumberFormatter|Collator|XSLTProcessor|tidy|GMP|SNMP|AMQPConnection|SSH2)["\'] not found|Call to undefined function\s+\\\\?(?:imagecreate|imagecreatetruecolor|imagejpeg|imagepng|exif_read_data|bcadd|bcmul|gmp_add|curl_init|mb_strlen|ldap_connect|imap_open|yaml_parse|msgpack_pack|igbinary_serialize|apcu_fetch|socket_create|gettext)\s*\(/i',
        'title' => 'A PHP extension this app needs is not installed',
        'explanation' => 'PHP reports the missing symbol, not the package — "Class \'Imagick\' not found" means the imagick extension is absent for this site\'s PHP version. dply can read the extension out of the error and install it for that version only; other sites keep the extensions they have.',
        'actions' => [
            [
                'key' => 'install_php_extension',
                'label' => 'Install the missing extension for this site\'s PHP',
                'recommended' => true,
                'auto_safe' => false,
                'handler' => InstallPhpExtensionAction::class,
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
