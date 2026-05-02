<?php

namespace App\Services\Servers;

class ServerInventoryProbeScript
{
    /** Watched systemd units (for the Services tab + Overview). PHP-FPM units discovered separately at runtime. */
    public const WATCHED_UNITS = [
        'nginx',
        'mysql',
        'mariadb',
        'redis-server',
        'redis',
        'supervisor',
        'supervisord',
        'fail2ban',
    ];

    /**
     * Build the inventory + manage probe shell script. Extended adds disk/memory/uptime/fail2ban
     * from the original inventory probe; UNITS and PORTS sections always run (they're cheap and
     * cental to the Manage page Overview/Services tabs).
     */
    public function build(bool $extended, int $previewLines): string
    {
        $previewLines = max(10, min(200, $previewLines));
        $watched = implode(' ', array_map('escapeshellarg', self::WATCHED_UNITS));

        $script = <<<SH
printf "OS_BEGIN\n"
cat /etc/os-release 2>/dev/null || true
printf "OS_END\n"
rb=0
[ -f /var/run/reboot-required ] && rb=1
up=0
if command -v apt >/dev/null 2>&1; then
  up=\$(apt list --upgradable 2>/dev/null | tail -n +2 | wc -l | tr -d " ")
fi
printf "reboot=%s\nupgrades=%s\nPACKAGES_BEGIN\n" "\$rb" "\$up"
if command -v apt >/dev/null 2>&1; then
  apt list --upgradable 2>/dev/null | tail -n +2 | head -n {$previewLines}
fi
printf "PACKAGES_END\n"
printf "UNITS_BEGIN\n"
if command -v systemctl >/dev/null 2>&1; then
  for u in {$watched}; do
    if systemctl list-unit-files "\$u.service" --no-legend --no-pager 2>/dev/null | grep -q "^\$u.service"; then
      systemctl show "\$u.service" --no-pager --property=Names,LoadState,ActiveState,SubState,ActiveEnterTimestamp,MemoryCurrent 2>/dev/null
      printf "UNIT_SEP\n"
    fi
  done
  for fpm in \$(systemctl list-unit-files 'php*-fpm.service' --no-legend --no-pager 2>/dev/null | awk '{print \$1}'); do
    [ -z "\$fpm" ] && continue
    systemctl show "\$fpm" --no-pager --property=Names,LoadState,ActiveState,SubState,ActiveEnterTimestamp,MemoryCurrent 2>/dev/null
    printf "UNIT_SEP\n"
  done
fi
printf "UNITS_END\n"
printf "PORTS_BEGIN\n"
if command -v ss >/dev/null 2>&1; then
  (sudo -n ss -lntpH 2>/dev/null || ss -lntpH 2>/dev/null) | head -n 60
elif command -v netstat >/dev/null 2>&1; then
  (sudo -n netstat -lntp 2>/dev/null || netstat -lntp 2>/dev/null) | tail -n +3 | head -n 60
fi
printf "PORTS_END\n"
printf "NGINX_BEGIN\n"
if command -v nginx >/dev/null 2>&1; then
  printf "PRESENT=1\n"
  printf "VERSION=%s\n" "\$(nginx -v 2>&1 | head -n 1)"
  printf "SITES_ENABLED_COUNT=%s\n" "\$(ls -1 /etc/nginx/sites-enabled/ 2>/dev/null | wc -l | tr -d ' ')"
  printf "CONF_D_COUNT=%s\n" "\$(ls -1 /etc/nginx/conf.d/ 2>/dev/null | wc -l | tr -d ' ')"
fi
printf "NGINX_END\n"
printf "PHP_FPM_BEGIN\n"
for d in /etc/php/*; do
  [ -d "\$d/fpm" ] || continue
  v=\$(basename "\$d")
  pools=\$(ls -1 "\$d/fpm/pool.d/"*.conf 2>/dev/null | wc -l | tr -d ' ')
  active=unknown
  if command -v systemctl >/dev/null 2>&1; then
    active=\$(systemctl is-active "php\${v}-fpm" 2>/dev/null || echo "unknown")
  fi
  printf "VERSION=%s|ACTIVE=%s|POOLS=%s\n" "\$v" "\$active" "\$pools"
done
printf "PHP_FPM_END\n"
printf "MYSQL_BEGIN\n"
if command -v mysql >/dev/null 2>&1; then
  printf "PRESENT=1\n"
  printf "VERSION=%s\n" "\$(mysql --version 2>/dev/null | head -n 1)"
fi
if command -v mariadb >/dev/null 2>&1; then
  printf "MARIADB_PRESENT=1\n"
  printf "MARIADB_VERSION=%s\n" "\$(mariadb --version 2>/dev/null | head -n 1)"
fi
printf "MYSQL_END\n"
printf "REDIS_BEGIN\n"
if command -v redis-cli >/dev/null 2>&1; then
  printf "PRESENT=1\n"
  printf "INFO_BEGIN\n"
  (redis-cli INFO server 2>/dev/null; echo; redis-cli INFO clients 2>/dev/null; echo; redis-cli INFO memory 2>/dev/null; echo; redis-cli INFO stats 2>/dev/null; echo; redis-cli INFO persistence 2>/dev/null) | head -n 250 || true
  printf "INFO_END\n"
fi
printf "REDIS_END\n"
printf "CERTBOT_BEGIN\n"
if command -v certbot >/dev/null 2>&1; then
  printf "PRESENT=1\n"
  (sudo -n certbot certificates --no-color 2>/dev/null || certbot certificates --no-color 2>/dev/null) | head -n 300
fi
printf "CERTBOT_END\n"
printf "AUTOUP_BEGIN\n"
if [ -r /etc/apt/apt.conf.d/20auto-upgrades ]; then
  printf "PRESENT=1\n"
  cat /etc/apt/apt.conf.d/20auto-upgrades 2>/dev/null
fi
printf "AUTOUP_END\n"
printf "LAST_APT_UPDATE=%s\n" "\$(stat -c %Y /var/lib/apt/periodic/update-success-stamp 2>/dev/null || echo 0)"
printf "DISCOVERED_BEGIN\n"
echo "[nginx_sites_enabled]"
ls -1 /etc/nginx/sites-enabled/ 2>/dev/null | head -n 50
echo "[nginx_conf_d]"
ls -1 /etc/nginx/conf.d/ 2>/dev/null | head -n 50
echo "[supervisor_conf_d]"
ls -1 /etc/supervisor/conf.d/ 2>/dev/null | head -n 50
printf "DISCOVERED_END\n"
SH;

        if ($extended) {
            $script .= <<<'SH'


printf "EXTENDED_BEGIN\n"
df -h 2>/dev/null | head -n 25
printf "\n---\n"
uptime 2>/dev/null || true
printf "\n---\n"
free -h 2>/dev/null | head -n 8 || true
printf "\n---\n"
(command -v systemctl >/dev/null 2>&1 && systemctl is-active fail2ban 2>/dev/null) || echo "n/a"
printf "EXTENDED_END\n"
SH;
        }

        return $script;
    }

    /**
     * Parse the probe output and merge the extracted state into a fresh meta array.
     * Caller passes existing meta; returns the new meta to persist.
     *
     * @param  array<string, mixed>  $existingMeta
     * @return array<string, mixed>
     */
    public function parse(string $out, array $existingMeta, int $maxPreviewBytes, int $maxExtBytes): array
    {
        $meta = $existingMeta;

        // OS
        $osReleaseRaw = null;
        if (preg_match('/OS_BEGIN\s*\R(.*?)\ROS_END/s', $out, $osM)) {
            $osReleaseRaw = trim($osM[1]);
        }
        $detected = ServerInventoryOsDetector::fromOsRelease($osReleaseRaw ?? '');
        if ($detected['pretty'] !== null && $detected['pretty'] !== '') {
            $meta['inventory_os_pretty'] = $detected['pretty'];
        } else {
            unset($meta['inventory_os_pretty']);
        }
        if ($detected['key'] !== null) {
            $meta['inventory_os_detected_key'] = $detected['key'];
        } else {
            unset($meta['inventory_os_detected_key']);
        }
        $currentOs = (string) ($meta['os_version'] ?? '');
        if ($currentOs === '' && $detected['key'] !== null) {
            $meta['os_version'] = $detected['key'];
        }

        // reboot, upgrades flag/count
        $reboot = null;
        $upgrades = null;
        foreach (explode("\n", $out) as $line) {
            if (str_starts_with($line, 'reboot=')) {
                $reboot = trim(substr($line, 7)) === '1';
            }
            if (str_starts_with($line, 'upgrades=')) {
                $upgrades = max(0, (int) trim(substr($line, 9)));
            }
        }
        $meta['inventory_reboot_required'] = $reboot;
        $meta['inventory_upgradable_packages'] = $upgrades;

        // Upgradable preview
        $pkgPreview = null;
        if (preg_match('/PACKAGES_BEGIN\s*\R(.*?)\RPACKAGES_END/s', $out, $m)) {
            $pkgPreview = trim($m[1]);
        }
        if ($pkgPreview !== null && strlen($pkgPreview) > $maxPreviewBytes) {
            $pkgPreview = substr($pkgPreview, 0, $maxPreviewBytes)."\n\n[dply] Preview truncated.";
        }
        if ($pkgPreview !== null && $pkgPreview !== '') {
            $meta['inventory_upgradable_preview'] = $pkgPreview;
        } else {
            unset($meta['inventory_upgradable_preview']);
        }

        // Extended snapshot
        $extendedSnapshot = null;
        if (preg_match('/EXTENDED_BEGIN\s*\R(.*?)\REXTENDED_END/s', $out, $ex)) {
            $extendedSnapshot = trim($ex[1]);
        }
        if ($extendedSnapshot !== null && strlen($extendedSnapshot) > $maxExtBytes) {
            $extendedSnapshot = substr($extendedSnapshot, 0, $maxExtBytes)."\n\n[dply] Preview truncated.";
        }
        if ($extendedSnapshot !== null && $extendedSnapshot !== '') {
            $meta['inventory_extended_snapshot'] = $extendedSnapshot;
        } else {
            unset($meta['inventory_extended_snapshot']);
        }

        // Watched units
        $unitsBlock = '';
        if (preg_match('/UNITS_BEGIN\s*\R(.*?)\RUNITS_END/s', $out, $u)) {
            $unitsBlock = trim($u[1]);
        }
        $units = [];
        if ($unitsBlock !== '') {
            foreach (preg_split('/\R?UNIT_SEP\R?/', $unitsBlock) as $chunk) {
                $chunk = trim($chunk);
                if ($chunk === '') {
                    continue;
                }
                $u = ['unit' => null, 'load_state' => null, 'active_state' => null, 'sub_state' => null, 'active_enter_at' => null, 'memory_current_bytes' => null];
                foreach (explode("\n", $chunk) as $line) {
                    [$k, $v] = array_pad(explode('=', trim($line), 2), 2, '');
                    switch ($k) {
                        case 'Names':
                            // Names can be space-separated; take the first that ends in .service
                            foreach (preg_split('/\s+/', $v) as $name) {
                                if (str_ends_with($name, '.service')) {
                                    $u['unit'] = preg_replace('/\.service$/', '', $name);
                                    break;
                                }
                            }
                            break;
                        case 'LoadState': $u['load_state'] = $v;
                            break;
                        case 'ActiveState': $u['active_state'] = $v;
                            break;
                        case 'SubState': $u['sub_state'] = $v;
                            break;
                        case 'ActiveEnterTimestamp':
                            $u['active_enter_at'] = $v !== '' ? $v : null;
                            break;
                        case 'MemoryCurrent':
                            $u['memory_current_bytes'] = is_numeric($v) ? (int) $v : null;
                            break;
                    }
                }
                if ($u['unit'] !== null && $u['load_state'] !== 'not-found') {
                    $units[] = $u;
                }
            }
        }
        $meta['manage_units'] = $units;

        // Listening ports (raw block — parsed in the view for the small port table).
        $ports = '';
        if (preg_match('/PORTS_BEGIN\s*\R(.*?)\RPORTS_END/s', $out, $p)) {
            $ports = trim($p[1]);
        }
        if (strlen($ports) > 16384) {
            $ports = substr($ports, 0, 16384)."\n[dply] truncated";
        }
        if ($ports !== '') {
            $meta['manage_listening_ports'] = $ports;
        } else {
            unset($meta['manage_listening_ports']);
        }

        // nginx
        $meta['manage_nginx'] = $this->extractKvBlock($out, 'NGINX');

        // PHP-FPM
        $phpFpm = ['versions' => []];
        if (preg_match('/PHP_FPM_BEGIN\s*\R(.*?)\RPHP_FPM_END/s', $out, $pm)) {
            foreach (explode("\n", trim($pm[1])) as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $kv = [];
                foreach (explode('|', $line) as $pair) {
                    [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
                    $kv[trim($k)] = trim($v);
                }
                if (! empty($kv['VERSION'])) {
                    $phpFpm['versions'][] = [
                        'version' => $kv['VERSION'],
                        'active' => $kv['ACTIVE'] ?? 'unknown',
                        'pools_count' => isset($kv['POOLS']) ? (int) $kv['POOLS'] : 0,
                    ];
                }
            }
        }
        $meta['manage_php_fpm'] = $phpFpm;

        // MySQL/MariaDB
        $meta['manage_mysql'] = $this->extractKvBlock($out, 'MYSQL');

        // Redis (key/value plus a fenced INFO_BEGIN/INFO_END inner block)
        $redis = $this->extractKvBlock($out, 'REDIS');
        if (preg_match('/REDIS_BEGIN.*?INFO_BEGIN\s*\R(.*?)\RINFO_END.*?REDIS_END/s', $out, $rm)) {
            $info = trim($rm[1]);
            if (strlen($info) > 16384) {
                $info = substr($info, 0, 16384)."\n[dply] truncated";
            }
            if ($info !== '') {
                $redis['info_raw'] = $info;
            }
        }
        $meta['manage_redis'] = $redis;

        // Certbot
        $cb = ['present' => false, 'certs_raw' => null];
        if (preg_match('/CERTBOT_BEGIN\s*\R(.*?)\RCERTBOT_END/s', $out, $cm)) {
            $body = trim($cm[1]);
            if ($body !== '') {
                $cb['present'] = (bool) preg_match('/^PRESENT=1/m', $body);
                $rest = preg_replace('/^PRESENT=\d\R?/m', '', $body);
                if (is_string($rest) && trim($rest) !== '') {
                    $cb['certs_raw'] = trim($rest);
                }
            }
        }
        $meta['manage_certbot'] = $cb;

        // Unattended-upgrades
        $uu = ['present' => false, 'enabled' => null, 'snippet' => null];
        if (preg_match('/AUTOUP_BEGIN\s*\R(.*?)\RAUTOUP_END/s', $out, $um)) {
            $body = trim($um[1]);
            if ($body !== '') {
                $uu['present'] = (bool) preg_match('/^PRESENT=1/m', $body);
                $snippet = preg_replace('/^PRESENT=\d\R?/m', '', $body);
                if (is_string($snippet)) {
                    $uu['snippet'] = trim($snippet);
                    if ($uu['snippet'] !== '' && preg_match('/Unattended-Upgrade\s*"(\d)"/', $uu['snippet'], $em)) {
                        $uu['enabled'] = $em[1] === '1';
                    }
                }
            }
        }
        $meta['manage_unattended_upgrades'] = $uu;

        // Last apt update
        $meta['manage_last_apt_update'] = null;
        foreach (explode("\n", $out) as $line) {
            if (str_starts_with($line, 'LAST_APT_UPDATE=')) {
                $ts = (int) trim(substr($line, 16));
                $meta['manage_last_apt_update'] = $ts > 0 ? date('c', $ts) : null;
                break;
            }
        }

        // Discovered files
        $disc = ['nginx_sites_enabled' => [], 'nginx_conf_d' => [], 'supervisor_conf_d' => []];
        if (preg_match('/DISCOVERED_BEGIN\s*\R(.*?)\RDISCOVERED_END/s', $out, $dm)) {
            $current = null;
            foreach (explode("\n", trim($dm[1])) as $line) {
                $line = rtrim($line);
                if (preg_match('/^\[(\w+)\]$/', trim($line), $hm)) {
                    $current = $hm[1];

                    continue;
                }
                if ($current === null || trim($line) === '') {
                    continue;
                }
                if (isset($disc[$current])) {
                    $disc[$current][] = trim($line);
                }
            }
        }
        $meta['manage_discovered'] = $disc;

        $meta['inventory_checked_at'] = now()->toIso8601String();

        return $meta;
    }

    /**
     * Extract a `<TAG>_BEGIN` / `<TAG>_END` block of `KEY=VALUE` lines into a lower-cased assoc array.
     * Special key `PRESENT` is normalized to a boolean. Empty block → empty array.
     *
     * @return array<string, mixed>
     */
    private function extractKvBlock(string $out, string $tag): array
    {
        if (! preg_match('/'.preg_quote($tag, '/').'_BEGIN\s*\R(.*?)\R'.preg_quote($tag, '/').'_END/s', $out, $m)) {
            return [];
        }
        $kv = [];
        foreach (explode("\n", trim($m[1])) as $line) {
            $line = trim($line);
            if ($line === '' || ! str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $key = strtolower(trim($k));
            $kv[$key] = trim($v);
        }
        if (isset($kv['present'])) {
            $kv['present'] = $kv['present'] === '1';
        }
        if (isset($kv['mariadb_present'])) {
            $kv['mariadb_present'] = $kv['mariadb_present'] === '1';
        }

        return $kv;
    }
}
