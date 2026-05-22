<?php

/**
 * Console catalog — dply CLI section.
 *
 * Always shown (the `dply` tag is in ServerInstalledServices::ALWAYS_PRESENT).
 * Mirrors the bash subcommands defined in resources/bin/dply — keep these
 * in sync when the script grows.
 */
return [
    'label' => 'dply CLI',
    'description' => 'Operator wrapper for common server actions. Reads /etc/dply/state.json — no network calls.',
    'requires_any_tags' => ['dply'],
    'entries' => [
        ['command' => 'dply status', 'description' => 'Uptime, load, kernel, and live state of managed systemd units.'],
        ['command' => 'dply help', 'description' => 'Full list of subcommands and aliases.'],
        ['command' => 'dply version', 'description' => 'Print the installed CLI version.'],
        ['command' => 'dply restart php', 'description' => 'Graceful FPM restart (resolves the right php-fpm unit per server).'],
        ['command' => 'dply restart web', 'description' => 'Restart the active webserver (nginx/apache/caddy).'],
        ['command' => 'dply tail nginx', 'description' => 'Follow nginx/error.log (alias resolves to the real path).'],
        ['command' => 'dply tail php', 'description' => 'Follow the php-fpm log.'],
        ['command' => 'dply tail syslog', 'description' => 'Follow /var/log/syslog.'],
        ['command' => 'dply site list', 'description' => 'Sites managed by dply on this server.'],
        ['command' => 'dply site show <name>', 'description' => 'Detail for one site (path, deploy script).'],
        ['command' => 'dply recipe list', 'description' => 'Saved Run-page commands cached locally for offline use.'],
        ['command' => 'dply recipe run <name>', 'description' => 'Execute a saved recipe by name.'],
        ['command' => 'dply ssl list', 'description' => 'Installed Let\'s Encrypt certificates and expiry dates.'],
        ['command' => 'dply ssl renew', 'description' => 'Renew certificates (append --dry-run to test).'],
    ],
];
