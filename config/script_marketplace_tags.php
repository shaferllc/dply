<?php

/**
 * Tag map for the marketplace presets in config/script_marketplace.php.
 *
 * Stored separately so the original preset shape (key → name/run_as_user/content)
 * stays untouched for the rest of the codebase. The recipes-page library merges
 * these in at render time.
 *
 * Conventions:
 *  - Each preset gets 1–3 tags. Lowercase, hyphenated.
 *  - The vocabulary is intentionally narrow (~20 tags) so the filter UI stays
 *    readable. Reuse existing tags before inventing new ones.
 *  - When in doubt: pick the noun the operator would search for at 3 a.m.
 */
return [
    // Original 24 presets
    'ubuntu-apt-update' => ['system', 'apt'],
    'php-composer-self-update' => ['runtime', 'php'],
    'disk-usage-summary' => ['disk'],
    'memory-and-load' => ['performance'],
    'listening-ports' => ['network'],
    'systemd-failed-units' => ['process-manager', 'logs'],
    'nginx-test-and-reload' => ['web', 'nginx'],
    'certbot-certificates' => ['tls'],
    'certbot-renew-dry-run' => ['tls'],
    'redis-ping' => ['database', 'redis'],
    'docker-ps' => ['docker'],
    'docker-compose-pull' => ['docker'],
    'git-fetch-prune' => ['system'],
    'laravel-clear-caches' => ['laravel'],
    'laravel-optimize' => ['laravel'],
    'laravel-queue-restart' => ['laravel'],
    'laravel-down-maintenance' => ['laravel'],
    'laravel-up-maintenance' => ['laravel'],
    'laravel-storage-permissions' => ['laravel'],
    'laravel-artisan-about' => ['laravel'],
    'laravel-horizon-terminate' => ['laravel'],
    'php-and-extensions' => ['runtime', 'php'],
    'log-tail-nginx-error' => ['logs', 'nginx'],
    'inode-usage' => ['disk'],

    // System & package managers
    'apt-list-upgradable' => ['system', 'apt'],
    'apt-autoremove' => ['system', 'apt'],
    'apt-held-packages' => ['system', 'apt'],
    'dnf-update' => ['system'],
    'reboot-required-check' => ['system'],
    'kernel-and-uname' => ['system'],
    'cpu-info' => ['system'],
    'who-and-last' => ['system', 'security'],
    'ntp-status' => ['system'],
    'set-timezone-utc' => ['system'],
    'journal-disk-usage' => ['logs', 'system'],
    'journal-vacuum-7d' => ['logs', 'system'],
    'swap-status' => ['system', 'performance'],
    'create-swapfile-2g' => ['system'],
    'sysctl-key-tunings' => ['system'],
    'env-dump-safe' => ['system'],

    // Networking
    'ip-and-routes' => ['network'],
    'public-ip' => ['network'],
    'curl-self-https' => ['network', 'web'],
    'dns-check' => ['network'],
    'reverse-dns' => ['network'],
    'port-check-localhost' => ['network'],
    'ufw-status' => ['network', 'security'],
    'ufw-allow-http-https' => ['network', 'security'],
    'iptables-list' => ['network', 'security'],
    'tcp-connection-counts' => ['network'],
    'top-talkers' => ['network'],
    'mtu-and-mss' => ['network'],

    // Disk & filesystem
    'top-20-largest-files' => ['disk'],
    'du-top-dirs-var' => ['disk'],
    'du-top-dirs-home' => ['disk'],
    'lsblk-tree' => ['disk'],
    'mount-points' => ['disk'],
    'lsof-listening' => ['disk', 'network'],
    'lsof-deleted-but-open' => ['disk'],
    'fstab-and-uuids' => ['disk'],

    // Databases
    'mysql-ping' => ['database', 'mysql'],
    'mysql-process-list' => ['database', 'mysql'],
    'mysql-status-summary' => ['database', 'mysql'],
    'mysql-database-sizes' => ['database', 'mysql'],
    'mariadb-slow-queries-tail' => ['database', 'mysql', 'logs'],
    'postgres-ping' => ['database', 'postgres'],
    'postgres-active-connections' => ['database', 'postgres'],
    'postgres-database-sizes' => ['database', 'postgres'],
    'postgres-vacuum-analyze-all' => ['database', 'postgres'],
    'postgres-locks' => ['database', 'postgres'],
    'redis-info-summary' => ['database', 'redis'],
    'redis-slowlog-tail' => ['database', 'redis', 'logs'],
    'redis-keys-by-prefix-count' => ['database', 'redis'],

    // Web servers
    'apache-test-and-reload' => ['web'],
    'caddy-validate-and-reload' => ['web'],
    'haproxy-check-and-reload' => ['web'],
    'nginx-show-active-vhosts' => ['web', 'nginx'],
    'nginx-access-log-top-paths' => ['web', 'nginx', 'logs'],
    'nginx-access-log-top-ips' => ['web', 'nginx', 'logs'],
    'nginx-5xx-ratio-recent' => ['web', 'nginx', 'logs'],

    // Process & performance
    'top-cpu-15' => ['performance'],
    'top-mem-15' => ['performance'],
    'iostat-15s' => ['performance', 'disk'],
    'vmstat-15s' => ['performance'],
    'pidstat-cpu' => ['performance'],
    'pidstat-io' => ['performance', 'disk'],
    'load-and-runqueue' => ['performance'],
    'oomkiller-recent' => ['performance', 'logs'],

    // Docker & containers
    'docker-system-df' => ['docker'],
    'docker-image-prune' => ['docker'],
    'docker-system-prune-safe' => ['docker'],
    'docker-restart-all' => ['docker'],
    'docker-logs-tail' => ['docker', 'logs'],
    'docker-compose-restart' => ['docker'],
    'docker-stats-snapshot' => ['docker', 'performance'],
    'docker-volume-ls' => ['docker', 'disk'],

    // Security & access
    'sshd-config-test' => ['security'],
    'sshd-effective-config' => ['security'],
    'authorized-keys-by-user' => ['security'],
    'fail2ban-status' => ['security'],
    'fail2ban-unban-all' => ['security'],
    'sudo-log-tail' => ['security', 'logs'],
    'failed-ssh-logins' => ['security', 'logs'],
    'world-writable-files' => ['security'],
    'sudoers-include-list' => ['security'],

    // TLS / certificates
    'tls-expiry-localhost' => ['tls', 'web'],
    'tls-expiry-domain' => ['tls', 'web'],
    'letsencrypt-expiry-list' => ['tls'],
    'tls-cipher-test' => ['tls', 'web'],

    // Language runtimes
    'node-version-check' => ['runtime'],
    'python-version-check' => ['runtime'],
    'ruby-version-check' => ['runtime'],
    'go-version-check' => ['runtime'],
    'java-version-check' => ['runtime'],
    'php-fpm-pools' => ['runtime', 'php'],
    'php-fpm-status-summary' => ['runtime', 'php'],
    'composer-platform-check' => ['runtime', 'php'],

    // Process managers
    'systemd-service-status' => ['process-manager'],
    'systemd-list-running' => ['process-manager'],
    'systemd-recent-failures' => ['process-manager', 'logs'],
    'supervisor-status' => ['process-manager'],
    'pm2-list' => ['process-manager'],
    'pm2-restart-all' => ['process-manager'],
    'cron-list-all-users' => ['process-manager'],

    // Backups & rsync
    'rsync-test-dry-run' => ['backup'],
    'tar-snapshot-etc' => ['backup'],
    'mysqldump-all-gz' => ['backup', 'database', 'mysql'],
    'pgdump-all-gz' => ['backup', 'database', 'postgres'],
    'rotate-old-files-7d' => ['system'],

    // Application-specific
    'laravel-pail-tail' => ['laravel', 'logs'],
    'laravel-schedule-list' => ['laravel'],
    'laravel-route-list' => ['laravel'],
    'laravel-failed-jobs' => ['laravel'],
    'laravel-prod-cache-warm' => ['laravel'],
    'rails-routes-list' => ['rails'],
    'rails-tail-production-log' => ['rails', 'logs'],
    'django-migrate-check' => ['django'],
    'django-collectstatic-dry' => ['django'],

    // Misc
    'show-host-fingerprint' => ['security'],
    'shell-history-top' => ['system'],
    'logrotate-debug' => ['logs', 'system'],
    'unattended-upgrades-status' => ['system', 'logs'],
    'cgroup-mem-top' => ['performance'],
];
