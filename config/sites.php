<?php

return [

    'nginx_sites_available' => env('DPLY_NGINX_SITES_AVAILABLE', '/etc/nginx/sites-available'),

    'nginx_sites_enabled' => env('DPLY_NGINX_SITES_ENABLED', '/etc/nginx/sites-enabled'),

    'apache_sites_available' => env('DPLY_APACHE_SITES_AVAILABLE', '/etc/apache2/sites-available'),

    'apache_sites_enabled' => env('DPLY_APACHE_SITES_ENABLED', '/etc/apache2/sites-enabled'),

    'caddy_sites_enabled' => env('DPLY_CADDY_SITES_ENABLED', '/etc/caddy/sites-enabled'),

    'openlitespeed_vhosts_path' => env('DPLY_OLS_VHOSTS_PATH', '/usr/local/lsws/conf/vhosts'),

    'openlitespeed_httpd_config' => env('DPLY_OLS_HTTPD_CONFIG', '/usr/local/lsws/conf/httpd_config.conf'),

    'traefik_dynamic_config_path' => env('DPLY_TRAEFIK_DYNAMIC_CONFIG_PATH', '/etc/traefik/dynamic'),

    'traefik_static_config' => env('DPLY_TRAEFIK_STATIC_CONFIG', '/etc/traefik/traefik.yml'),

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
