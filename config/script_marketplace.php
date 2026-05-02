<?php

/**
 * Preset scripts users can clone into their organization and edit.
 *
 * Keys are stable IDs; names and bodies can be updated between releases.
 */
return [
    'ubuntu-apt-update' => [
        'name' => 'APT update (Ubuntu)',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get upgrade -y
echo "APT update finished."
SH,
    ],
    'php-composer-self-update' => [
        'name' => 'Composer self-update',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
if command -v composer >/dev/null 2>&1; then
  composer self-update --no-interaction
  echo "Composer updated."
else
  echo "composer not found in PATH" >&2
  exit 1
fi
SH,
    ],
    'disk-usage-summary' => [
        'name' => 'Disk usage summary',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
df -hT
echo "---"
du -sh /var/www/* 2>/dev/null || true
SH,
    ],
    'memory-and-load' => [
        'name' => 'Memory, load, and uptime',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
uptime
echo "---"
free -h 2>/dev/null || vm_stat
echo "---"
ps aux --sort=-%mem | head -n 15
SH,
    ],
    'listening-ports' => [
        'name' => 'Listening TCP ports (ss)',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
if command -v ss >/dev/null 2>&1; then
  ss -tulpn
else
  netstat -tulpn 2>/dev/null || netstat -tuln
fi
SH,
    ],
    'systemd-failed-units' => [
        'name' => 'Systemd failed units',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
systemctl --failed --no-pager || true
SH,
    ],
    'nginx-test-and-reload' => [
        'name' => 'Nginx: test config and reload',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
nginx -t
if command -v systemctl >/dev/null 2>&1; then
  systemctl reload nginx
else
  service nginx reload
fi
echo "Nginx config OK and reload requested."
SH,
    ],
    'certbot-certificates' => [
        'name' => 'Certbot: list certificates',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
if command -v certbot >/dev/null 2>&1; then
  certbot certificates
else
  echo "certbot not installed" >&2
  exit 1
fi
SH,
    ],
    'certbot-renew-dry-run' => [
        'name' => 'Certbot: renew dry run',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
certbot renew --dry-run
SH,
    ],
    'redis-ping' => [
        'name' => 'Redis: PING',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
if command -v redis-cli >/dev/null 2>&1; then
  redis-cli PING
else
  echo "redis-cli not found" >&2
  exit 1
fi
SH,
    ],
    'php-and-extensions' => [
        'name' => 'PHP version and loaded extensions',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
php -v
echo "---"
php -m | sort
SH,
    ],
    'laravel-artisan-about' => [
        'name' => 'Laravel: php artisan about',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
APP_DIR="${APP_DIR:-/var/www}"
cd "$APP_DIR" || exit 1
php artisan about
SH,
    ],
    'laravel-clear-caches' => [
        'name' => 'Laravel: clear caches (no optimize)',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
APP_DIR="${APP_DIR:-/var/www}"
cd "$APP_DIR" || exit 1
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear
echo "Laravel caches cleared."
SH,
    ],
    'laravel-optimize' => [
        'name' => 'Laravel: config, route, and view cache',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
APP_DIR="${APP_DIR:-/var/www}"
cd "$APP_DIR" || exit 1
php artisan config:cache
php artisan route:cache
php artisan view:cache
echo "Laravel optimize complete."
SH,
    ],
    'laravel-down-maintenance' => [
        'name' => 'Laravel: enable maintenance mode',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
APP_DIR="${APP_DIR:-/var/www}"
cd "$APP_DIR" || exit 1
php artisan down --render="errors::503" || php artisan down
echo "Maintenance mode on."
SH,
    ],
    'laravel-up-maintenance' => [
        'name' => 'Laravel: disable maintenance mode',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
APP_DIR="${APP_DIR:-/var/www}"
cd "$APP_DIR" || exit 1
php artisan up
echo "Maintenance mode off."
SH,
    ],
    'laravel-queue-restart' => [
        'name' => 'Laravel: queue workers restart signal',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
APP_DIR="${APP_DIR:-/var/www}"
cd "$APP_DIR" || exit 1
php artisan queue:restart
echo "Sent queue:restart (workers should recycle)."
SH,
    ],
    'laravel-horizon-terminate' => [
        'name' => 'Laravel Horizon: terminate',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
APP_DIR="${APP_DIR:-/var/www}"
cd "$APP_DIR" || exit 1
php artisan horizon:terminate
echo "Horizon terminate sent."
SH,
    ],
    'laravel-storage-permissions' => [
        'name' => 'Laravel: fix storage & bootstrap/cache perms (www-data)',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
APP_DIR="${APP_DIR:-/var/www}"
cd "$APP_DIR" || exit 1
chown -R www-data:www-data storage bootstrap/cache
find storage bootstrap/cache -type d -exec chmod 775 {} \;
find storage bootstrap/cache -type f -exec chmod 664 {} \;
echo "Permissions updated for www-data."
SH,
    ],
    'git-fetch-prune' => [
        'name' => 'Git: fetch and prune remotes',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
REPO_DIR="${REPO_DIR:-/var/www}"
cd "$REPO_DIR" || exit 1
git fetch --all --prune
git status -sb
SH,
    ],
    'docker-ps' => [
        'name' => 'Docker: running containers',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
if command -v docker >/dev/null 2>&1; then
  docker ps
else
  echo "docker not found" >&2
  exit 1
fi
SH,
    ],
    'docker-compose-pull' => [
        'name' => 'Docker Compose: pull images',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
COMPOSE_DIR="${COMPOSE_DIR:-/var/www}"
cd "$COMPOSE_DIR" || exit 1
if docker compose version >/dev/null 2>&1; then
  docker compose pull
elif command -v docker-compose >/dev/null 2>&1; then
  docker-compose pull
else
  echo "docker compose not available" >&2
  exit 1
fi
SH,
    ],
    'log-tail-nginx-error' => [
        'name' => 'Tail nginx error log (last 80 lines)',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
for f in /var/log/nginx/error.log /var/log/nginx/error.log.1; do
  if [[ -f "$f" ]]; then
    echo "=== $f ==="
    tail -n 80 "$f"
    exit 0
  fi
done
echo "No nginx error log found in /var/log/nginx/" >&2
exit 1
SH,
    ],
    'inode-usage' => [
        'name' => 'Disk inode usage',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
df -ih
SH,
    ],

    // ─── System & package managers ────────────────────────────────────────────
    'apt-list-upgradable' => [
        'name' => 'APT: list upgradable packages',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
apt-get update -y >/dev/null
apt list --upgradable 2>/dev/null
SH,
    ],
    'apt-autoremove' => [
        'name' => 'APT: autoremove + clean',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
apt-get autoremove -y
apt-get autoclean -y
SH,
    ],
    'apt-held-packages' => [
        'name' => 'APT: list held packages',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
apt-mark showhold
SH,
    ],
    'dnf-update' => [
        'name' => 'DNF/YUM update (RHEL family)',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
if command -v dnf >/dev/null 2>&1; then
  dnf -y upgrade --refresh
else
  yum -y update
fi
SH,
    ],
    'reboot-required-check' => [
        'name' => 'Reboot required?',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
if [ -f /var/run/reboot-required ]; then
  echo "Reboot required."
  cat /var/run/reboot-required.pkgs 2>/dev/null || true
else
  echo "No reboot pending."
fi
SH,
    ],
    'kernel-and-uname' => [
        'name' => 'Kernel and OS info',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
uname -a
echo "---"
cat /etc/os-release 2>/dev/null || true
SH,
    ],
    'cpu-info' => [
        'name' => 'CPU info',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
lscpu 2>/dev/null || cat /proc/cpuinfo
SH,
    ],
    'who-and-last' => [
        'name' => 'Who is logged in / last logins',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
who
echo "---"
last -n 15
SH,
    ],
    'ntp-status' => [
        'name' => 'NTP / time sync status',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
if command -v timedatectl >/dev/null 2>&1; then
  timedatectl status
else
  date
  ntpq -p 2>/dev/null || true
fi
SH,
    ],
    'set-timezone-utc' => [
        'name' => 'Set timezone to UTC',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
timedatectl set-timezone UTC
timedatectl status
SH,
    ],
    'journal-disk-usage' => [
        'name' => 'Journald disk usage',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
journalctl --disk-usage
SH,
    ],
    'journal-vacuum-7d' => [
        'name' => 'Journald: keep last 7 days',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
journalctl --vacuum-time=7d
journalctl --disk-usage
SH,
    ],
    'swap-status' => [
        'name' => 'Swap usage',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
swapon --show
echo "---"
free -h
SH,
    ],
    'create-swapfile-2g' => [
        'name' => 'Create 2G swapfile (idempotent)',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
if swapon --show | grep -q "/swapfile"; then
  echo "/swapfile already active."
  exit 0
fi
fallocate -l 2G /swapfile
chmod 600 /swapfile
mkswap /swapfile
swapon /swapfile
grep -q "/swapfile" /etc/fstab || echo "/swapfile none swap sw 0 0" >> /etc/fstab
swapon --show
SH,
    ],
    'sysctl-key-tunings' => [
        'name' => 'Common sysctl values',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
for k in vm.swappiness fs.file-max net.core.somaxconn net.ipv4.tcp_max_syn_backlog net.ipv4.ip_forward; do
  printf '%s = %s\n' "$k" "$(sysctl -n "$k" 2>/dev/null || echo n/a)"
done
SH,
    ],
    'env-dump-safe' => [
        'name' => 'Dump non-secret environment',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
env | sort | grep -Ev '(_KEY|_TOKEN|_SECRET|PASSWORD|PASSWD)='
SH,
    ],

    // ─── Networking ───────────────────────────────────────────────────────────
    'ip-and-routes' => [
        'name' => 'IPs and routing table',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
ip -br addr
echo "---"
ip route
SH,
    ],
    'public-ip' => [
        'name' => 'Public IP (multiple sources)',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
for src in https://ifconfig.me https://api.ipify.org https://ipinfo.io/ip; do
  echo -n "$src: "
  curl -fsS --max-time 5 "$src" || echo "(failed)"
  echo
done
SH,
    ],
    'curl-self-https' => [
        'name' => 'Curl localhost over HTTPS',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
curl -kIs --max-time 5 https://127.0.0.1/ | head -n 20
SH,
    ],
    'dns-check' => [
        'name' => 'DNS lookup (A + MX)',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
HOST="${1:-example.com}"
for type in A AAAA MX TXT NS; do
  echo "== $type =="
  dig +short "$HOST" "$type" 2>/dev/null || nslookup -type="$type" "$HOST" 2>/dev/null || true
done
SH,
    ],
    'reverse-dns' => [
        'name' => 'Reverse DNS for this host',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
ip -br -4 addr | awk '{print $3}' | tr ',' '\n' | awk -F/ '{print $1}' | while read -r ip; do
  [ -n "$ip" ] || continue
  echo -n "$ip → "
  dig +short -x "$ip" 2>/dev/null || echo "(no PTR)"
done
SH,
    ],
    'port-check-localhost' => [
        'name' => 'Check common ports on localhost',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
for p in 22 80 443 3306 5432 6379 11211 27017; do
  if (echo > "/dev/tcp/127.0.0.1/$p") >/dev/null 2>&1; then
    echo "$p OPEN"
  else
    echo "$p closed"
  fi
done
SH,
    ],
    'ufw-status' => [
        'name' => 'UFW: status verbose',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
ufw status verbose
SH,
    ],
    'ufw-allow-http-https' => [
        'name' => 'UFW: allow HTTP + HTTPS',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
ufw allow 80/tcp
ufw allow 443/tcp
ufw status verbose
SH,
    ],
    'iptables-list' => [
        'name' => 'iptables: list rules (all tables)',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
for t in filter nat mangle; do
  echo "== $t =="
  iptables -t "$t" -L -n -v --line-numbers 2>/dev/null || true
done
SH,
    ],
    'tcp-connection-counts' => [
        'name' => 'TCP connection counts by state',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
ss -tan | awk 'NR>1 {print $1}' | sort | uniq -c | sort -rn
SH,
    ],
    'top-talkers' => [
        'name' => 'Top remote IPs (current connections)',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
ss -tn state established | awk 'NR>1 {print $4}' | awk -F: '{print $1}' | sort | uniq -c | sort -rn | head -n 20
SH,
    ],
    'mtu-and-mss' => [
        'name' => 'Interface MTU and MSS',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
ip -br link
echo "---"
ip route get 1.1.1.1 2>/dev/null || true
SH,
    ],

    // ─── Disk & filesystem ────────────────────────────────────────────────────
    'top-20-largest-files' => [
        'name' => 'Top 20 largest files (under /)',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
find / -xdev -type f -size +50M -printf '%s\t%p\n' 2>/dev/null | sort -rn | head -n 20 | awk '{ printf "%10.1f MB  %s\n", $1/1024/1024, $2 }'
SH,
    ],
    'du-top-dirs-var' => [
        'name' => 'Largest dirs under /var',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
du -h --max-depth=2 /var 2>/dev/null | sort -h | tail -n 25
SH,
    ],
    'du-top-dirs-home' => [
        'name' => 'Largest dirs under /home',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
du -h --max-depth=2 /home 2>/dev/null | sort -h | tail -n 25
SH,
    ],
    'lsblk-tree' => [
        'name' => 'Block devices (lsblk)',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
lsblk -f
SH,
    ],
    'mount-points' => [
        'name' => 'Mounted filesystems',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
findmnt -t nosquashfs,nobinfmt_misc,noproc,nosysfs,nocgroup,nocgroup2,nodebugfs,noautofs,notmpfs,nodevpts,nomqueue,nofusectl,nopstore,nosecurityfs,nohugetlbfs,notracefs --notruncate || mount
SH,
    ],
    'lsof-listening' => [
        'name' => 'Processes listening on TCP',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
if command -v lsof >/dev/null 2>&1; then
  lsof -nP -iTCP -sTCP:LISTEN
else
  ss -tlnp
fi
SH,
    ],
    'lsof-deleted-but-open' => [
        'name' => 'Open files marked deleted',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
lsof +L1 2>/dev/null | head -n 50
SH,
    ],
    'fstab-and-uuids' => [
        'name' => 'fstab + UUIDs',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
cat /etc/fstab
echo "---"
blkid
SH,
    ],

    // ─── Databases ────────────────────────────────────────────────────────────
    'mysql-ping' => [
        'name' => 'MySQL/MariaDB: ping',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
mysqladmin ping 2>&1 || echo "Could not ping MySQL — check ~/.my.cnf or set MYSQL_PWD."
SH,
    ],
    'mysql-process-list' => [
        'name' => 'MySQL: process list',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
mysql -e 'SHOW PROCESSLIST;'
SH,
    ],
    'mysql-status-summary' => [
        'name' => 'MySQL: key status counters',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
mysql -e "SHOW GLOBAL STATUS LIKE 'Threads_connected'; SHOW GLOBAL STATUS LIKE 'Aborted_connects'; SHOW GLOBAL STATUS LIKE 'Slow_queries'; SHOW GLOBAL STATUS LIKE 'Uptime';"
SH,
    ],
    'mysql-database-sizes' => [
        'name' => 'MySQL: database sizes',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
mysql -e "SELECT table_schema AS db, ROUND(SUM(data_length+index_length)/1024/1024,1) AS size_mb FROM information_schema.tables GROUP BY table_schema ORDER BY size_mb DESC;"
SH,
    ],
    'mariadb-slow-queries-tail' => [
        'name' => 'MySQL/MariaDB: tail slow log',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
for f in /var/log/mysql/mysql-slow.log /var/log/mariadb/mariadb-slow.log; do
  if [ -f "$f" ]; then
    tail -n 100 "$f"
    exit 0
  fi
done
echo "Slow log not found." >&2
exit 1
SH,
    ],
    'postgres-ping' => [
        'name' => 'PostgreSQL: ping (pg_isready)',
        'run_as_user' => 'postgres',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
pg_isready
SH,
    ],
    'postgres-active-connections' => [
        'name' => 'PostgreSQL: active connections',
        'run_as_user' => 'postgres',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
psql -c "SELECT datname, count(*) FROM pg_stat_activity GROUP BY datname ORDER BY count DESC;"
SH,
    ],
    'postgres-database-sizes' => [
        'name' => 'PostgreSQL: database sizes',
        'run_as_user' => 'postgres',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
psql -c "SELECT datname, pg_size_pretty(pg_database_size(datname)) FROM pg_database ORDER BY pg_database_size(datname) DESC;"
SH,
    ],
    'postgres-vacuum-analyze-all' => [
        'name' => 'PostgreSQL: vacuum analyze (all DBs)',
        'run_as_user' => 'postgres',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
vacuumdb --all --analyze --verbose 2>&1 | tail -n 50
SH,
    ],
    'postgres-locks' => [
        'name' => 'PostgreSQL: blocked sessions',
        'run_as_user' => 'postgres',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
psql -c "SELECT pid, age(clock_timestamp(), query_start), state, wait_event_type, wait_event, query FROM pg_stat_activity WHERE state <> 'idle' AND wait_event IS NOT NULL ORDER BY age DESC;"
SH,
    ],
    'redis-info-summary' => [
        'name' => 'Redis: info summary',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
redis-cli INFO server | head -n 20
echo "---"
redis-cli INFO memory | head -n 20
echo "---"
redis-cli INFO stats | head -n 20
SH,
    ],
    'redis-slowlog-tail' => [
        'name' => 'Redis: slow log (last 25)',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
redis-cli SLOWLOG GET 25
SH,
    ],
    'redis-keys-by-prefix-count' => [
        'name' => 'Redis: keys by prefix (sampled)',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
redis-cli --scan --count 1000 | awk -F: '{print $1}' | sort | uniq -c | sort -rn | head -n 25
SH,
    ],

    // ─── Web servers ──────────────────────────────────────────────────────────
    'apache-test-and-reload' => [
        'name' => 'Apache: test config and reload',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
SVC=apache2
if ! command -v apache2 >/dev/null 2>&1; then SVC=httpd; fi
if command -v apachectl >/dev/null 2>&1; then apachectl configtest; fi
systemctl reload "$SVC"
SH,
    ],
    'caddy-validate-and-reload' => [
        'name' => 'Caddy: validate + reload',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
caddy validate --config /etc/caddy/Caddyfile
caddy reload --config /etc/caddy/Caddyfile
SH,
    ],
    'haproxy-check-and-reload' => [
        'name' => 'HAProxy: check config + reload',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
haproxy -c -V -f /etc/haproxy/haproxy.cfg
systemctl reload haproxy
SH,
    ],
    'nginx-show-active-vhosts' => [
        'name' => 'Nginx: list active server_names',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
nginx -T 2>/dev/null | awk '/server_name/ {gsub(";",""); $1=""; print}' | sort -u
SH,
    ],
    'nginx-access-log-top-paths' => [
        'name' => 'Nginx: top paths in access log',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
LOG=${NGINX_ACCESS_LOG:-/var/log/nginx/access.log}
awk '{print $7}' "$LOG" | sort | uniq -c | sort -rn | head -n 25
SH,
    ],
    'nginx-access-log-top-ips' => [
        'name' => 'Nginx: top client IPs',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
LOG=${NGINX_ACCESS_LOG:-/var/log/nginx/access.log}
awk '{print $1}' "$LOG" | sort | uniq -c | sort -rn | head -n 20
SH,
    ],
    'nginx-5xx-ratio-recent' => [
        'name' => 'Nginx: 5xx ratio in last 1k requests',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
LOG=${NGINX_ACCESS_LOG:-/var/log/nginx/access.log}
tail -n 1000 "$LOG" | awk '{print $9}' | awk 'BEGIN{t=0;e=0} {t++; if ($1 ~ /^5/) e++} END{ if (t>0) printf "5xx: %d / %d (%.2f%%)\n", e, t, 100*e/t }'
SH,
    ],

    // ─── Process & performance ────────────────────────────────────────────────
    'top-cpu-15' => [
        'name' => 'Top 15 by CPU',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
ps aux --sort=-%cpu | head -n 16
SH,
    ],
    'top-mem-15' => [
        'name' => 'Top 15 by memory',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
ps aux --sort=-%mem | head -n 16
SH,
    ],
    'iostat-15s' => [
        'name' => 'iostat (3 × 5s sample)',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
if ! command -v iostat >/dev/null 2>&1; then echo "Install sysstat for iostat" >&2; exit 1; fi
iostat -xz 5 3
SH,
    ],
    'vmstat-15s' => [
        'name' => 'vmstat (3 × 5s sample)',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
vmstat 5 3
SH,
    ],
    'pidstat-cpu' => [
        'name' => 'pidstat: CPU per-process (5s)',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
if ! command -v pidstat >/dev/null 2>&1; then echo "Install sysstat for pidstat" >&2; exit 1; fi
pidstat -u 5 1
SH,
    ],
    'pidstat-io' => [
        'name' => 'pidstat: IO per-process (5s)',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
pidstat -d 5 1
SH,
    ],
    'load-and-runqueue' => [
        'name' => 'Load average + run queue',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
uptime
echo "---"
cat /proc/loadavg
echo "---"
cat /proc/pressure/cpu 2>/dev/null || true
SH,
    ],
    'oomkiller-recent' => [
        'name' => 'Recent OOM kills',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
journalctl -k -p err -n 200 | grep -i -E 'killed process|out of memory' || echo "No recent OOM events."
SH,
    ],

    // ─── Docker & containers ──────────────────────────────────────────────────
    'docker-system-df' => [
        'name' => 'Docker: disk usage',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
docker system df -v
SH,
    ],
    'docker-image-prune' => [
        'name' => 'Docker: prune dangling images',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
docker image prune -f
SH,
    ],
    'docker-system-prune-safe' => [
        'name' => 'Docker: prune (without volumes)',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
docker system prune -f
SH,
    ],
    'docker-restart-all' => [
        'name' => 'Docker: restart all running containers',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
docker ps --format '{{.Names}}' | xargs -r -n1 docker restart
SH,
    ],
    'docker-logs-tail' => [
        'name' => 'Docker: tail logs of one container',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
NAME="${1:-}"
if [ -z "$NAME" ]; then echo "Pass container name as argument." >&2; exit 1; fi
docker logs --tail 200 "$NAME"
SH,
    ],
    'docker-compose-restart' => [
        'name' => 'Docker Compose: restart in CWD',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
docker compose restart
docker compose ps
SH,
    ],
    'docker-stats-snapshot' => [
        'name' => 'Docker: stats snapshot',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
docker stats --no-stream
SH,
    ],
    'docker-volume-ls' => [
        'name' => 'Docker: volumes (sized)',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
for v in $(docker volume ls -q); do
  size=$(docker run --rm -v "$v":/v alpine sh -c "du -sh /v | cut -f1" 2>/dev/null || echo "?")
  printf '%-30s %s\n' "$v" "$size"
done
SH,
    ],

    // ─── Security & access ────────────────────────────────────────────────────
    'sshd-config-test' => [
        'name' => 'sshd: config test',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
sshd -t
echo "OK — sshd config valid."
SH,
    ],
    'sshd-effective-config' => [
        'name' => 'sshd: effective settings',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
sshd -T 2>/dev/null | sort
SH,
    ],
    'authorized-keys-by-user' => [
        'name' => 'List authorized_keys per user',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
awk -F: '$3 >= 1000 || $1 == "root" {print $1, $6}' /etc/passwd | while read -r u home; do
  ak="$home/.ssh/authorized_keys"
  if [ -f "$ak" ]; then
    n=$(wc -l < "$ak" | tr -d ' ')
    echo "$u: $n keys at $ak"
  fi
done
SH,
    ],
    'fail2ban-status' => [
        'name' => 'Fail2ban: status (all jails)',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
fail2ban-client status
echo "---"
for j in $(fail2ban-client status | awk -F'\t' '/Jail list/ {print $2}' | tr ',' ' '); do
  echo "== $j =="
  fail2ban-client status "$j"
done
SH,
    ],
    'fail2ban-unban-all' => [
        'name' => 'Fail2ban: unban all IPs in sshd jail',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
J=sshd
ips=$(fail2ban-client status "$J" 2>/dev/null | awk -F: '/Banned IP list/ {print $2}')
for ip in $ips; do
  fail2ban-client set "$J" unbanip "$ip"
done
SH,
    ],
    'sudo-log-tail' => [
        'name' => 'Tail sudo log',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
journalctl -t sudo -n 100 --no-pager 2>/dev/null || tail -n 100 /var/log/auth.log 2>/dev/null || tail -n 100 /var/log/secure
SH,
    ],
    'failed-ssh-logins' => [
        'name' => 'Recent failed SSH logins',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
journalctl -u ssh -u sshd --since "24 hours ago" 2>/dev/null | grep -iE 'failed|invalid' | tail -n 50
SH,
    ],
    'world-writable-files' => [
        'name' => 'World-writable files (under /etc and /var/www)',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
find /etc /var/www -xdev -type f -perm -o+w -ls 2>/dev/null | head -n 100
SH,
    ],
    'sudoers-include-list' => [
        'name' => 'Sudoers files (visudo -c)',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
visudo -c
echo "---"
ls -l /etc/sudoers.d/ 2>/dev/null || true
SH,
    ],

    // ─── TLS / certificates ───────────────────────────────────────────────────
    'tls-expiry-localhost' => [
        'name' => 'TLS expiry (localhost:443)',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
echo | openssl s_client -connect 127.0.0.1:443 -servername localhost 2>/dev/null | openssl x509 -noout -dates -subject -issuer
SH,
    ],
    'tls-expiry-domain' => [
        'name' => 'TLS expiry for a domain',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
DOM="${1:-example.com}"
echo | openssl s_client -connect "$DOM":443 -servername "$DOM" 2>/dev/null | openssl x509 -noout -dates -subject -issuer
SH,
    ],
    'letsencrypt-expiry-list' => [
        'name' => "Let's Encrypt: certs expiring soon",
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
DIR=/etc/letsencrypt/live
[ -d "$DIR" ] || { echo "No /etc/letsencrypt/live"; exit 0; }
for cert in "$DIR"/*/cert.pem; do
  end=$(openssl x509 -enddate -noout -in "$cert" | cut -d= -f2)
  printf '%-40s %s\n' "$(basename "$(dirname "$cert")")" "$end"
done
SH,
    ],
    'tls-cipher-test' => [
        'name' => 'TLS ciphers offered (localhost)',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
echo | openssl s_client -connect 127.0.0.1:443 -tls1_3 2>/dev/null | grep -E 'Cipher|Protocol' | head -n 4
SH,
    ],

    // ─── Language runtimes ────────────────────────────────────────────────────
    'node-version-check' => [
        'name' => 'Node / npm versions',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
node -v 2>/dev/null || echo "node not installed"
npm -v 2>/dev/null || true
yarn -v 2>/dev/null || true
pnpm -v 2>/dev/null || true
SH,
    ],
    'python-version-check' => [
        'name' => 'Python / pip versions',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
python3 --version 2>/dev/null || python --version 2>/dev/null || echo "python not installed"
pip3 --version 2>/dev/null || true
SH,
    ],
    'ruby-version-check' => [
        'name' => 'Ruby / bundler versions',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
ruby -v 2>/dev/null || echo "ruby not installed"
bundle -v 2>/dev/null || true
SH,
    ],
    'go-version-check' => [
        'name' => 'Go version + GOPATH',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
go version 2>/dev/null || echo "go not installed"
go env GOPATH GOROOT 2>/dev/null || true
SH,
    ],
    'java-version-check' => [
        'name' => 'Java version + JVM info',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
java -version 2>&1 || echo "java not installed"
SH,
    ],
    'php-fpm-pools' => [
        'name' => 'PHP-FPM: list pools',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
ls -1 /etc/php/*/fpm/pool.d/*.conf 2>/dev/null || ls -1 /etc/php-fpm.d/*.conf 2>/dev/null || echo "No PHP-FPM pool configs found."
SH,
    ],
    'php-fpm-status-summary' => [
        'name' => 'PHP-FPM: status summary (curl)',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
curl -fsS http://127.0.0.1/php-fpm-status?full 2>/dev/null | head -n 50 || echo "Expose /php-fpm-status to 127.0.0.1 to use this."
SH,
    ],
    'composer-platform-check' => [
        'name' => 'Composer: platform requirements',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
composer check-platform-reqs 2>&1 || true
SH,
    ],

    // ─── Process managers ────────────────────────────────────────────────────
    'systemd-service-status' => [
        'name' => 'systemd: status of one service',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
SVC="${1:-nginx}"
systemctl status "$SVC" --no-pager
SH,
    ],
    'systemd-list-running' => [
        'name' => 'systemd: running units',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
systemctl list-units --state=running --no-pager
SH,
    ],
    'systemd-recent-failures' => [
        'name' => 'systemd: recent unit failures',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
journalctl -p err -n 200 --no-pager
SH,
    ],
    'supervisor-status' => [
        'name' => 'Supervisor: process status',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
if command -v supervisorctl >/dev/null 2>&1; then
  supervisorctl status
else
  echo "supervisorctl not installed"
fi
SH,
    ],
    'pm2-list' => [
        'name' => 'PM2: list processes',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
if command -v pm2 >/dev/null 2>&1; then
  pm2 list
else
  echo "pm2 not installed"
fi
SH,
    ],
    'pm2-restart-all' => [
        'name' => 'PM2: restart all apps',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
pm2 restart all
pm2 list
SH,
    ],
    'cron-list-all-users' => [
        'name' => 'Crontabs (system + per user)',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
echo "== /etc/crontab =="; cat /etc/crontab 2>/dev/null || true
echo; echo "== /etc/cron.d =="; ls -l /etc/cron.d/ 2>/dev/null || true
echo; echo "== per-user crontabs =="
for u in $(cut -f1 -d: /etc/passwd); do
  c=$(crontab -u "$u" -l 2>/dev/null || true)
  if [ -n "$c" ]; then echo "-- $u --"; echo "$c"; fi
done
SH,
    ],

    // ─── Backups & rsync ──────────────────────────────────────────────────────
    'rsync-test-dry-run' => [
        'name' => 'rsync: dry run preview (CWD → /tmp/_dry)',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
rsync -avn --delete ./ /tmp/_dry/ | head -n 100
SH,
    ],
    'tar-snapshot-etc' => [
        'name' => 'Snapshot /etc to /root/etc-YYYYMMDD.tgz',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
ts=$(date +%F)
out="/root/etc-${ts}.tgz"
tar -czf "$out" /etc
ls -lh "$out"
SH,
    ],
    'mysqldump-all-gz' => [
        'name' => 'mysqldump: all databases (gzipped)',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
ts=$(date +%F-%H%M)
out="/root/mysql-all-${ts}.sql.gz"
mysqldump --single-transaction --all-databases | gzip -c > "$out"
ls -lh "$out"
SH,
    ],
    'pgdump-all-gz' => [
        'name' => 'pg_dumpall (gzipped)',
        'run_as_user' => 'postgres',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
ts=$(date +%F-%H%M)
out="/var/lib/postgresql/all-${ts}.sql.gz"
pg_dumpall | gzip -c > "$out"
ls -lh "$out"
SH,
    ],
    'rotate-old-files-7d' => [
        'name' => 'Delete files older than 7d in /tmp/cache',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
DIR="${1:-/tmp/cache}"
[ -d "$DIR" ] || { echo "No such dir: $DIR"; exit 1; }
find "$DIR" -type f -mtime +7 -print -delete | wc -l
SH,
    ],

    // ─── Application-specific ─────────────────────────────────────────────────
    'laravel-pail-tail' => [
        'name' => 'Laravel: tail with pail (15s)',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
cd /var/www
timeout 15s php artisan pail || true
SH,
    ],
    'laravel-schedule-list' => [
        'name' => 'Laravel: schedule:list',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
cd /var/www
php artisan schedule:list
SH,
    ],
    'laravel-route-list' => [
        'name' => 'Laravel: route:list',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
cd /var/www
php artisan route:list --columns=method,uri,name | head -n 80
SH,
    ],
    'laravel-failed-jobs' => [
        'name' => 'Laravel: failed-jobs list',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
cd /var/www
php artisan queue:failed | head -n 50
SH,
    ],
    'laravel-prod-cache-warm' => [
        'name' => 'Laravel: warm production caches',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
cd /var/www
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache 2>/dev/null || true
SH,
    ],
    'rails-routes-list' => [
        'name' => 'Rails: routes',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
cd /var/www
bundle exec rails routes | head -n 80
SH,
    ],
    'rails-tail-production-log' => [
        'name' => 'Rails: tail production.log',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
tail -n 200 /var/www/log/production.log 2>/dev/null || tail -n 200 /var/www/current/log/production.log
SH,
    ],
    'django-migrate-check' => [
        'name' => 'Django: pending migrations',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
cd /var/www
python manage.py showmigrations --plan | grep -E '^\s*\[ \]' || echo "No pending migrations."
SH,
    ],
    'django-collectstatic-dry' => [
        'name' => 'Django: collectstatic (dry run)',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
cd /var/www
python manage.py collectstatic --noinput --dry-run | head -n 40
SH,
    ],

    // ─── Misc one-shots ──────────────────────────────────────────────────────
    'show-host-fingerprint' => [
        'name' => 'Show this host SSH fingerprints',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
for f in /etc/ssh/ssh_host_*_key.pub; do
  ssh-keygen -lf "$f" 2>/dev/null
done
SH,
    ],
    'shell-history-top' => [
        'name' => 'Top shell history (current user)',
        'run_as_user' => null,
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
HIST="${HISTFILE:-$HOME/.bash_history}"
[ -f "$HIST" ] || HIST="$HOME/.zsh_history"
awk '{ a[$1]++ } END { for (c in a) print a[c], c }' "$HIST" 2>/dev/null | sort -rn | head -n 25
SH,
    ],
    'logrotate-debug' => [
        'name' => 'logrotate: dry-run debug',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
logrotate -d /etc/logrotate.conf 2>&1 | tail -n 80
SH,
    ],
    'unattended-upgrades-status' => [
        'name' => 'Unattended-upgrades status',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
cat /var/log/unattended-upgrades/unattended-upgrades.log 2>/dev/null | tail -n 80 || echo "Log not present."
SH,
    ],
    'cgroup-mem-top' => [
        'name' => 'cgroup memory leaders',
        'run_as_user' => 'root',
        'content' => <<<'SH'
#!/bin/bash
set -euo pipefail
if [ -d /sys/fs/cgroup ]; then
  find /sys/fs/cgroup -name memory.current -print -exec cat {} \; 2>/dev/null | paste - - | sort -k2 -rn | head -n 20
fi
SH,
    ],
];
