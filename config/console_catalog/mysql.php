<?php

/**
 * Console catalog — MySQL / MariaDB section.
 *
 * Both surface as the `mysql` tag (they share log paths and CLI tools).
 */
return [
    'label' => 'MySQL',
    'description' => 'MySQL / MariaDB: status, processes, and logs.',
    'requires_any_tags' => ['mysql'],
    'entries' => [
        ['command' => 'mysql --version', 'description' => 'Client version.'],
        ['command' => 'sudo mysql -e "SELECT VERSION();"', 'description' => 'Server version (via socket auth).'],
        ['command' => 'sudo mysql -e "SHOW PROCESSLIST;"', 'description' => 'Current connections + running queries.'],
        ['command' => 'sudo mysql -e "SHOW DATABASES;"', 'description' => 'List databases.'],
        ['command' => 'sudo mysql -e "SHOW GLOBAL STATUS LIKE \'Threads_%\';"', 'description' => 'Connection thread counters.'],
        ['command' => 'systemctl status mysql --no-pager -n 20', 'description' => 'Service status.'],
        ['command' => 'sudo tail -n 200 /var/log/mysql/error.log', 'description' => 'Recent error log.'],
        ['command' => 'sudo mysqladmin status', 'description' => 'Uptime + thread + question counters.'],
    ],
];
