<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Run history retention
    |--------------------------------------------------------------------------
    |
    | `dply:prune-cron-job-runs` removes rows older than this many days.
    |
    */
    'run_retention_days' => (int) env('DPLY_CRON_RUN_RETENTION_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | One-click cron bundles
    |--------------------------------------------------------------------------
    |
    | Each bundle inserts one or more rows into server_cron_jobs (panel-side only;
    | user must run "Sync crontab" afterwards to install the Dply-managed block).
    | Commands often contain placeholders (your-app, example.com) — the user is
    | expected to edit the row before syncing.
    |
    | Schema per bundle:
    |   label        — title shown on the bundle card
    |   description  — short explainer under the title
    |   entries      — list<array{cron_expression, command, description?, user?, overlap_policy?}>
    |
    */
    'bundled_jobs' => [
        // ── Laravel ──────────────────────────────────────────────────────────────────────
        'laravel_scheduler' => [
            'label' => 'Laravel scheduler',
            'description' => 'Runs `php artisan schedule:run` every minute. Edit the path to your app before syncing.',
            'entries' => [
                [
                    'cron_expression' => '* * * * *',
                    'command' => 'cd /home/deploy/your-app/current && php artisan schedule:run >> /dev/null 2>&1',
                    'description' => 'Laravel scheduler',
                    'user' => 'deploy',
                ],
            ],
        ],
        'laravel_horizon_snapshot' => [
            'label' => 'Laravel — Horizon snapshot',
            'description' => '`horizon:snapshot` every 5 minutes for queue metrics in Horizon.',
            'entries' => [
                [
                    'cron_expression' => '*/5 * * * *',
                    'command' => 'cd /home/deploy/your-app/current && php artisan horizon:snapshot >> /dev/null 2>&1',
                    'description' => 'Horizon metrics snapshot',
                    'user' => 'deploy',
                ],
            ],
        ],

        // ── WordPress ────────────────────────────────────────────────────────────────────
        'wordpress_wp_cron' => [
            'label' => 'WordPress — wp-cron via HTTP',
            'description' => 'Pings wp-cron.php every 15 minutes (set DISABLE_WP_CRON in wp-config). Edit the URL before syncing.',
            'entries' => [
                [
                    'cron_expression' => '*/15 * * * *',
                    'command' => 'curl -fsS -o /dev/null "https://example.com/wp-cron.php?doing_wp_cron"',
                    'description' => 'WordPress wp-cron (HTTP)',
                    'user' => 'deploy',
                ],
            ],
        ],

        // ── TLS ──────────────────────────────────────────────────────────────────────────
        'certbot_renew' => [
            'label' => "Let's Encrypt — auto-renew",
            'description' => 'Renews ACME certificates daily at 03:00 and reloads Nginx on success.',
            'entries' => [
                [
                    'cron_expression' => '0 3 * * *',
                    'command' => 'certbot renew --quiet --deploy-hook "systemctl reload nginx"',
                    'description' => "Let's Encrypt renewal",
                    'user' => 'root',
                ],
            ],
        ],

        // ── Database backups ─────────────────────────────────────────────────────────────
        'mysql_nightly_dump' => [
            'label' => 'MySQL — nightly dump',
            'description' => 'Compressed mysqldump nightly at 02:30 with 14-day retention. Edit credentials/path before syncing.',
            'entries' => [
                [
                    'cron_expression' => '30 2 * * *',
                    'command' => 'mysqldump --defaults-file=/root/.my.cnf --single-transaction --routines --events --all-databases | gzip > /var/backups/mysql/all-$(date +\\%F).sql.gz && find /var/backups/mysql -name "*.sql.gz" -mtime +14 -delete',
                    'description' => 'MySQL nightly dump (14-day retention)',
                    'user' => 'root',
                ],
            ],
        ],
        'postgres_nightly_dump' => [
            'label' => 'PostgreSQL — nightly dump',
            'description' => 'pg_dumpall nightly at 02:30 with 14-day retention. Edit user/path before syncing.',
            'entries' => [
                [
                    'cron_expression' => '30 2 * * *',
                    'command' => 'sudo -u postgres pg_dumpall | gzip > /var/backups/postgres/all-$(date +\\%F).sql.gz && find /var/backups/postgres -name "*.sql.gz" -mtime +14 -delete',
                    'description' => 'PostgreSQL nightly dump (14-day retention)',
                    'user' => 'root',
                ],
            ],
        ],

        // ── Server hygiene ───────────────────────────────────────────────────────────────
        'server_hygiene' => [
            'label' => 'Server hygiene',
            'description' => 'Weekly apt upgrade + journalctl vacuum + fstrim. Conservative defaults; review before syncing.',
            'entries' => [
                [
                    'cron_expression' => '0 6 * * 0',
                    'command' => 'export DEBIAN_FRONTEND=noninteractive; apt-get update -qq && apt-get -y -qq upgrade',
                    'description' => 'Weekly apt upgrade',
                    'user' => 'root',
                ],
                [
                    'cron_expression' => '0 5 * * 0',
                    'command' => 'journalctl --vacuum-time=14d >/dev/null 2>&1',
                    'description' => 'Weekly journalctl vacuum (14d)',
                    'user' => 'root',
                ],
                [
                    'cron_expression' => '0 4 * * 0',
                    'command' => '/sbin/fstrim -a -v 2>&1 | logger -t fstrim',
                    'description' => 'Weekly SSD trim',
                    'user' => 'root',
                ],
            ],
        ],
        'logrotate_nginx' => [
            'label' => 'Nginx — logrotate',
            'description' => 'Forces Nginx logrotate nightly at midnight (in addition to the OS default).',
            'entries' => [
                [
                    'cron_expression' => '0 0 * * *',
                    'command' => '/usr/sbin/logrotate -f /etc/logrotate.d/nginx 2>&1 | logger -t nginx-logrotate',
                    'description' => 'Nginx log rotation',
                    'user' => 'root',
                ],
            ],
        ],
    ],

];
