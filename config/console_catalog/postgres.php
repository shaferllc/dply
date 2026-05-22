<?php

/**
 * Console catalog — PostgreSQL section.
 */
return [
    'label' => 'PostgreSQL',
    'description' => 'Postgres: status, activity, and logs.',
    'requires_any_tags' => ['postgres'],
    'entries' => [
        ['command' => 'psql --version', 'description' => 'Client version.'],
        ['command' => 'sudo -u postgres psql -c "SELECT version();"', 'description' => 'Server version.'],
        ['command' => 'sudo -u postgres psql -c "\\l"', 'description' => 'List databases.'],
        ['command' => 'sudo -u postgres psql -c "SELECT pid, usename, application_name, state, query_start, left(query, 80) FROM pg_stat_activity ORDER BY query_start DESC;"', 'description' => 'Current connections + recent queries.'],
        ['command' => 'sudo -u postgres psql -c "SELECT datname, pg_size_pretty(pg_database_size(datname)) FROM pg_database ORDER BY pg_database_size(datname) DESC;"', 'description' => 'Database sizes.'],
        ['command' => 'systemctl status postgresql --no-pager -n 20', 'description' => 'Service status.'],
        ['command' => 'sudo ls -la /var/log/postgresql/', 'description' => 'Available log files.'],
        ['command' => 'sudo tail -n 200 $(ls -t /var/log/postgresql/postgresql-*.log | head -n 1)', 'description' => 'Most recent Postgres log.'],
    ],
];
