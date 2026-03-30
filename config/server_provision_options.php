<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Server create wizard — provision preferences (stored in servers.meta)
    |--------------------------------------------------------------------------
    |
    | Optional keys on rows (server_roles, cache_services, webservers,
    | php_versions, databases):
    |
    | - providers (list): if set, this row only appears when form.type matches.
    | - exclude_providers (list): hide this row for these form.type values.
    | - requires_linked_credential (bool): hide until the org has a credential.
    |
    | - only_server_roles (list): if set, row appears only for these server_role
    |   values (e.g. a “Not applicable” sentinel for load_balancer).
    |
    | - exclude_server_roles (list): hide this row when server_role is in the list.
    |
    | PHP 7.5 was never released; the oldest supported 7.x option here is 7.4.
    |
    */
    'server_roles' => [
        ['id' => 'application', 'label' => 'Web server', 'detail' => 'NGINX, Apache, or Caddy; database (selectable); Redis or Valkey; Memcached; Certbot; Composer; PHP; NPM; Supervisor; Fail2ban; UFW'],
        ['id' => 'load_balancer', 'label' => 'Load balancer', 'detail' => 'HaProxy, Certbot, UFW'],
        ['id' => 'database', 'label' => 'Database server', 'detail' => 'Database engine (selectable, e.g. MySQL, MariaDB, PostgreSQL), UFW'],
        ['id' => 'redis', 'label' => 'Redis server', 'detail' => 'Redis, UFW'],
        ['id' => 'valkey', 'label' => 'Valkey server', 'detail' => 'Valkey, UFW', 'exclude_providers' => ['fly_io']],
        ['id' => 'worker', 'label' => 'Worker server', 'detail' => 'PHP, Supervisor'],
        ['id' => 'docker', 'label' => 'Docker server', 'detail' => 'Docker, Supervisor, UFW', 'exclude_providers' => ['fly_io']],
        ['id' => 'plain', 'label' => 'Plain server', 'detail' => 'UFW, Supervisor'],
    ],
    'cache_services' => [
        ['id' => 'redis', 'label' => 'Redis'],
        ['id' => 'valkey', 'label' => 'Valkey', 'exclude_providers' => ['fly_io']],
    ],
    'webservers' => [
        ['id' => 'nginx', 'label' => 'NGINX', 'exclude_server_roles' => ['load_balancer', 'database', 'redis', 'valkey', 'plain', 'worker']],
        ['id' => 'apache', 'label' => 'Apache (httpd)', 'exclude_server_roles' => ['load_balancer', 'database', 'redis', 'valkey', 'plain', 'worker']],
        ['id' => 'caddy', 'label' => 'Caddy', 'exclude_server_roles' => ['load_balancer', 'database', 'redis', 'valkey', 'plain', 'worker']],
        ['id' => 'none', 'label' => 'Not applicable', 'only_server_roles' => ['load_balancer', 'database', 'redis', 'valkey', 'plain', 'worker']],
    ],
    'php_versions' => [
        ['id' => '8.4', 'label' => 'PHP 8.4', 'exclude_server_roles' => ['load_balancer', 'database', 'redis', 'valkey', 'plain']],
        ['id' => '8.3', 'label' => 'PHP 8.3', 'exclude_server_roles' => ['load_balancer', 'database', 'redis', 'valkey', 'plain']],
        ['id' => '8.2', 'label' => 'PHP 8.2', 'exclude_server_roles' => ['load_balancer', 'database', 'redis', 'valkey', 'plain']],
        ['id' => '8.1', 'label' => 'PHP 8.1', 'exclude_server_roles' => ['load_balancer', 'database', 'redis', 'valkey', 'plain']],
        ['id' => '8.0', 'label' => 'PHP 8.0', 'exclude_server_roles' => ['load_balancer', 'database', 'redis', 'valkey', 'plain']],
        ['id' => '7.4', 'label' => 'PHP 7.4 (legacy)', 'exclude_server_roles' => ['load_balancer', 'database', 'redis', 'valkey', 'plain']],
        ['id' => 'none', 'label' => 'Not applicable', 'only_server_roles' => ['load_balancer', 'database', 'redis', 'valkey', 'plain']],
    ],
    'databases' => [
        ['id' => 'mysql84', 'label' => 'MySQL 8.4 (LTS)', 'exclude_server_roles' => ['load_balancer', 'redis', 'valkey', 'plain']],
        ['id' => 'mysql80', 'label' => 'MySQL 8.0', 'exclude_server_roles' => ['load_balancer', 'redis', 'valkey', 'plain']],
        ['id' => 'mysql57', 'label' => 'MySQL 5.7 (legacy)', 'exclude_server_roles' => ['load_balancer', 'redis', 'valkey', 'plain']],
        ['id' => 'mariadb114', 'label' => 'MariaDB 11.4', 'exclude_server_roles' => ['load_balancer', 'redis', 'valkey', 'plain']],
        ['id' => 'mariadb11', 'label' => 'MariaDB 11', 'exclude_server_roles' => ['load_balancer', 'redis', 'valkey', 'plain']],
        ['id' => 'mariadb1011', 'label' => 'MariaDB 10.11 (LTS)', 'exclude_server_roles' => ['load_balancer', 'redis', 'valkey', 'plain']],
        ['id' => 'postgres18', 'label' => 'PostgreSQL 18', 'exclude_server_roles' => ['load_balancer', 'redis', 'valkey', 'plain']],
        ['id' => 'postgres17', 'label' => 'PostgreSQL 17', 'exclude_server_roles' => ['load_balancer', 'redis', 'valkey', 'plain']],
        ['id' => 'postgres16', 'label' => 'PostgreSQL 16', 'exclude_server_roles' => ['load_balancer', 'redis', 'valkey', 'plain']],
        ['id' => 'postgres15', 'label' => 'PostgreSQL 15', 'exclude_server_roles' => ['load_balancer', 'redis', 'valkey', 'plain']],
        ['id' => 'postgres14', 'label' => 'PostgreSQL 14', 'exclude_server_roles' => ['load_balancer', 'redis', 'valkey', 'plain']],
        ['id' => 'sqlite3', 'label' => 'SQLite 3', 'exclude_server_roles' => ['load_balancer', 'redis', 'valkey', 'plain']],
        ['id' => 'none', 'label' => 'None (external or not on this host)', 'only_server_roles' => ['application', 'docker', 'worker', 'load_balancer', 'redis', 'valkey', 'plain']],
    ],
];
