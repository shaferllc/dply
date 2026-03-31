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
    'install_profiles' => [
        [
            'id' => 'laravel_app',
            'label' => 'Laravel app',
            'summary' => 'Balanced Laravel application server with NGINX, PHP, Redis, and MySQL.',
            'server_role' => 'application',
            'cache_service' => 'redis',
            'webserver' => 'nginx',
            'php_version' => '8.3',
            'database' => 'mysql84',
        ],
        [
            'id' => 'php_api',
            'label' => 'PHP API',
            'summary' => 'Lean PHP API server with NGINX, PHP, and PostgreSQL.',
            'server_role' => 'application',
            'cache_service' => 'redis',
            'webserver' => 'nginx',
            'php_version' => '8.3',
            'database' => 'postgres17',
        ],
        [
            'id' => 'queue_worker',
            'label' => 'Queue worker',
            'summary' => 'Background worker host focused on queues and scheduled jobs.',
            'server_role' => 'worker',
            'cache_service' => 'redis',
            'webserver' => 'none',
            'php_version' => '8.3',
            'database' => 'none',
        ],
        [
            'id' => 'database_node',
            'label' => 'Database node',
            'summary' => 'Dedicated database host with no web stack.',
            'server_role' => 'database',
            'cache_service' => 'redis',
            'webserver' => 'none',
            'php_version' => 'none',
            'database' => 'mysql84',
        ],
        [
            'id' => 'static_app_host',
            'label' => 'Static app host',
            'summary' => 'Minimal host for static sites, proxies, or simple edge delivery.',
            'server_role' => 'plain',
            'cache_service' => 'redis',
            'webserver' => 'none',
            'php_version' => 'none',
            'database' => 'none',
        ],
    ],

    'server_roles' => [
        [
            'id' => 'application',
            'label' => 'Web server',
            'detail' => 'NGINX, Apache, or Caddy; database (selectable); Redis or Valkey; Memcached; Certbot; Composer; PHP; NPM; Supervisor; Fail2ban; UFW',
            'summary' => 'Best for hosting PHP apps with a full application stack on one server.',
            'installs' => ['NGINX, Apache, or Caddy', 'PHP', 'Composer', 'NPM', 'Selectable database', 'Redis or Valkey', 'Memcached', 'Certbot', 'Supervisor', 'Fail2ban', 'UFW'],
            'best_for' => 'Laravel, WordPress, and other app servers that need web, cache, queue, and database services together.',
            'does_not_include' => 'Does not split web, database, and cache services across dedicated machines.',
        ],
        [
            'id' => 'load_balancer',
            'label' => 'Load balancer',
            'detail' => 'HaProxy, Certbot, UFW',
            'summary' => 'Routes traffic across other application servers and terminates SSL at the edge.',
            'installs' => ['HAProxy', 'Certbot', 'UFW'],
            'best_for' => 'High-availability setups with multiple app servers behind one public entrypoint.',
            'does_not_include' => 'Does not install PHP, a database, Redis, or your application runtime.',
        ],
        [
            'id' => 'database',
            'label' => 'Database server',
            'detail' => 'Database engine (selectable, e.g. MySQL, MariaDB, PostgreSQL), UFW',
            'summary' => 'Dedicated data host for one database engine without the web stack.',
            'installs' => ['Selectable database engine', 'UFW'],
            'best_for' => 'Teams that want the database isolated from the application server for scale, security, or maintenance.',
            'does_not_include' => 'Does not install PHP, a web server, Redis, or queue workers.',
        ],
        [
            'id' => 'redis',
            'label' => 'Redis server',
            'detail' => 'Redis, UFW',
            'summary' => 'Dedicated Redis host for cache, queues, sessions, or pub/sub workloads.',
            'installs' => ['Redis', 'UFW'],
            'best_for' => 'Offloading cache and queue traffic from your app or database server.',
            'does_not_include' => 'Does not install PHP, a web server, or a relational database.',
        ],
        [
            'id' => 'valkey',
            'label' => 'Valkey server',
            'detail' => 'Valkey, UFW',
            'summary' => 'Dedicated Valkey host for cache and queue workloads with a Redis-compatible engine.',
            'installs' => ['Valkey', 'UFW'],
            'best_for' => 'Teams that want a standalone cache server using Valkey instead of Redis.',
            'does_not_include' => 'Does not install PHP, a web server, or a relational database.',
            'exclude_providers' => ['fly_io'],
        ],
        [
            'id' => 'worker',
            'label' => 'Worker server',
            'detail' => 'PHP, Supervisor',
            'summary' => 'Runs background jobs and queue workers without hosting the public web app.',
            'installs' => ['PHP', 'Supervisor'],
            'best_for' => 'Queues, scheduled jobs, Horizon-style workloads, and offloading background processing from the main app server.',
            'does_not_include' => 'Does not install a public web server, SSL tooling, or a database engine.',
        ],
        [
            'id' => 'docker',
            'label' => 'Docker server',
            'detail' => 'Docker, Supervisor, UFW',
            'summary' => 'Prepared host for container-based workloads instead of the standard PHP app stack.',
            'installs' => ['Docker', 'Supervisor', 'UFW'],
            'best_for' => 'Custom container deployments where you manage services inside Docker yourself.',
            'does_not_include' => 'Does not install the default PHP web stack or a database engine for you.',
            'exclude_providers' => ['fly_io'],
        ],
        [
            'id' => 'plain',
            'label' => 'Plain server',
            'detail' => 'UFW, Supervisor',
            'summary' => 'Minimal baseline host with only the essentials needed for access and process management.',
            'installs' => ['UFW', 'Supervisor'],
            'best_for' => 'Advanced setups where you plan to install and manage the runtime yourself.',
            'does_not_include' => 'Does not install PHP, a web server, Docker, Redis, or a database engine.',
        ],
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
