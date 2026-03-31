<?php

return [

    'nginx_sites_available' => env('DPLY_NGINX_SITES_AVAILABLE', '/etc/nginx/sites-available'),

    'nginx_sites_enabled' => env('DPLY_NGINX_SITES_ENABLED', '/etc/nginx/sites-enabled'),

    /*
    | {version} is replaced with site php_version (e.g. 8.3).
    */
    'php_fpm_socket' => env('DPLY_PHP_FPM_SOCKET', '/run/php/php{version}-fpm.sock'),

    'certbot_email' => env('DPLY_CERTBOT_EMAIL'),

    'supervisor_conf_d' => env('DPLY_SUPERVISOR_CONF_D', '/etc/supervisor/conf.d'),

    /**
     * systemd unit for the Supervisor daemon (Debian/Ubuntu package is usually "supervisor").
     */
    'supervisor_systemd_unit' => env('DPLY_SUPERVISOR_SYSTEMD_UNIT', 'supervisor'),

    /*
    | Webhook signing: preferred format uses X-Dply-Timestamp (unix seconds) + body signed as
    | HMAC-SHA256 of "{timestamp}.{rawBody}". Legacy body-only HMAC is still accepted.
    */
    'webhook_timestamp_tolerance' => (int) env('DPLY_WEBHOOK_TIMESTAMP_TOLERANCE', 300),

    'webhook_max_attempts_per_minute' => (int) env('DPLY_WEBHOOK_MAX_ATTEMPTS_PER_MINUTE', 30),

];
