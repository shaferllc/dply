<?php

/**
 * Console catalog — Redis / Valkey section.
 *
 * Valkey is wire-compatible with Redis so we treat both under the `redis` tag.
 */
return [
    'label' => 'Redis',
    'description' => 'In-memory cache: ping, stats, and live monitoring.',
    'requires_any_tags' => ['redis'],
    'entries' => [
        ['command' => 'redis-cli ping', 'description' => 'Healthcheck — should return PONG.'],
        ['command' => 'redis-cli info server | head -n 20', 'description' => 'Version, uptime, pid.'],
        ['command' => 'redis-cli info memory', 'description' => 'Memory usage stats.'],
        ['command' => 'redis-cli info clients', 'description' => 'Connected client count + activity.'],
        ['command' => 'redis-cli dbsize', 'description' => 'Number of keys in the default DB.'],
        ['command' => 'redis-cli --stat', 'description' => 'Live stat dashboard (Ctrl-C to exit).'],
        ['command' => 'redis-cli monitor', 'description' => 'Stream every command in real time. Heavy — for short bursts only.'],
        ['command' => 'sudo systemctl status redis-server --no-pager -n 10', 'description' => 'Service status.'],
        ['command' => 'tail -n 200 /var/log/redis/redis-server.log', 'description' => 'Recent Redis log.'],
    ],
];
