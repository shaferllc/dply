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

];
