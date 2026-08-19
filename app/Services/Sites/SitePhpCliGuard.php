<?php

declare(strict_types=1);

namespace App\Services\Sites;

use App\Models\Site;
use App\Services\WorkerPools\WorkerPhpVersion;

/**
 * Deploy steps must run the site's PHP, not Ubuntu's distro default.
 *
 * A worker cloned from an app server often boots 8.3.6 while the parent
 * site's lockfile requires ^8.4. Pin `php` to `/usr/bin/php{version}` and
 * install that package when it is missing (existing workers).
 */
class SitePhpCliGuard
{
    public function __construct(
        private WorkerPhpVersion $phpVersions,
    ) {}

    public function versionFor(Site $site): ?string
    {
        return $this->phpVersions->normalize($site->phpVersion());
    }

    /**
     * Shell that pins `~/.dply/bin/php` to the site version. Empty when the
     * site is not PHP. Does not include the wrapping `{ … } &&`.
     */
    public function prefix(Site $site): string
    {
        $version = $this->versionFor($site);
        if ($version === null) {
            return '';
        }

        $binary = '/usr/bin/php'.$version;
        $pkg = 'php'.$version.'-cli';

        return implode(' ', [
            'DPLY_PHP='.escapeshellarg($binary).';',
            'if [ ! -x "$DPLY_PHP" ]; then',
            'echo "[dply] PHP '.$version.' is required for this site but $DPLY_PHP is missing — installing…";',
            'if [ "$(id -u)" != 0 ] && ! sudo -n true >/dev/null 2>&1; then',
            'echo "[dply] ERROR: PHP '.$version.' is not installed and this user cannot install packages. Install '.$pkg.' on the server and redeploy." >&2;',
            'exit 1;',
            'fi;',
            'dply_php_sudo() { if [ "$(id -u)" = 0 ]; then "$@"; else sudo -n "$@"; fi; };',
            'export DEBIAN_FRONTEND=noninteractive;',
            'dply_php_sudo apt-get update -y -o Acquire::Retries=3 || true;',
            'if ! dply_php_sudo apt-cache show '.escapeshellarg($pkg).' >/dev/null 2>&1; then',
            'echo "[dply] '.$pkg.' is not in distro repos — adding ondrej/sury.";',
            'dply_php_sudo mkdir -p /etc/apt/keyrings;',
            'if curl -fsI -m 5 https://packages.sury.org/php/ >/dev/null 2>&1; then',
            'curl -fsSL --retry 3 --retry-delay 2 --max-time 60 https://packages.sury.org/php/apt.gpg | dply_php_sudo gpg --dearmor --yes -o /etc/apt/keyrings/sury-php.gpg;',
            'echo "deb [signed-by=/etc/apt/keyrings/sury-php.gpg] https://packages.sury.org/php/ $(lsb_release -cs) main" | dply_php_sudo tee /etc/apt/sources.list.d/sury-php.list >/dev/null;',
            'elif curl -fsI -m 5 https://ppa.launchpadcontent.net/ondrej/php/ubuntu/ >/dev/null 2>&1; then',
            'curl -fsSL --retry 3 --retry-delay 2 --max-time 60 "https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x14aa40ec0831756756d7f66c4f4ea0aae5267a6c" | dply_php_sudo gpg --dearmor --yes -o /etc/apt/keyrings/ondrej-php.gpg;',
            'echo "deb [signed-by=/etc/apt/keyrings/ondrej-php.gpg] https://ppa.launchpadcontent.net/ondrej/php/ubuntu $(lsb_release -cs) main" | dply_php_sudo tee /etc/apt/sources.list.d/ondrej-php.list >/dev/null;',
            'else',
            'echo "[dply] ERROR: cannot reach packages.sury.org or the ondrej PPA to install PHP '.$version.'." >&2;',
            'exit 1;',
            'fi;',
            'dply_php_sudo apt-get update -y -o Acquire::Retries=3;',
            'fi;',
            'dply_php_sudo apt-get install -y --no-install-recommends '
                .escapeshellarg($pkg).' '
                .escapeshellarg('php'.$version.'-common').' '
                .escapeshellarg('php'.$version.'-mbstring').' '
                .escapeshellarg('php'.$version.'-xml').' '
                .escapeshellarg('php'.$version.'-curl').' '
                .escapeshellarg('php'.$version.'-zip').' '
                .escapeshellarg('php'.$version.'-mysql').' '
                .escapeshellarg('php'.$version.'-pgsql').' '
                .escapeshellarg('php'.$version.'-sqlite3').' '
                .escapeshellarg('php'.$version.'-redis').';',
            'fi;',
            'if [ ! -x "$DPLY_PHP" ]; then',
            'echo "[dply] ERROR: '.$binary.' is still missing after install — Composer would run the wrong PHP." >&2;',
            'exit 1;',
            'fi;',
            'mkdir -p "$HOME/.dply/bin";',
            'ln -sfn "$DPLY_PHP" "$HOME/.dply/bin/php";',
            'echo "[dply] php CLI → $DPLY_PHP ($($DPLY_PHP -r \'echo PHP_VERSION;\' 2>/dev/null || true))";',
        ]).' ';
    }
}
