<?php

namespace App\Support\Console;

use App\Models\Server;
use App\Support\Servers\ServerInstalledServices;

/**
 * Curated argument-completion specs for the Console autocomplete.
 *
 * Returns a declarative tree the front-end can consume to decide what to
 * suggest after a space. Example shapes:
 *
 *   systemctl <verb> <unit>   → verbs static, units derived per server
 *   tail/less <path>          → known log paths per server
 *   journalctl -u <unit>      → after the -u flag, suggest units
 *
 * Full bash-completion is out of scope; we just cover the handful of commands
 * that hit "do I know what to type here?" in daily use.
 */
class ConsoleArgspecs
{
    /**
     * @return array<string, array{
     *     positional?: array<int, list<string>>,
     *     after_flag?: array<string, list<string>>,
     * }>
     */
    public static function for(Server $server): array
    {
        $units = self::unitsFor($server);
        $logs = self::logPathsFor($server);
        $tags = ServerInstalledServices::tagsFor($server);
        $phpVersion = ServerInstalledServices::phpVersionFor($server);

        $verbs = [
            'start', 'stop', 'restart', 'reload', 'status',
            'enable', 'disable', 'is-active', 'is-enabled',
        ];

        $sudoVerbs = [
            'start', 'stop', 'restart', 'reload', 'status',
            'enable', 'disable', 'mask', 'unmask', 'is-active', 'is-enabled',
        ];

        // Build dply subcommands based on installed services
        $dplySubcommands = ['status', 'help', 'version', 'restart', 'tail', 'site', 'recipe', 'ssl'];
        $dplyRestartServices = [];
        $dplyTailServices = [];

        if (isset($tags['php'])) {
            $dplyRestartServices[] = 'php';
            $dplyTailServices[] = 'php';
        }
        if (isset($tags['nginx']) || isset($tags['apache']) || isset($tags['caddy'])) {
            $dplyRestartServices[] = 'web';
        }
        if (isset($tags['nginx'])) {
            $dplyTailServices[] = 'nginx';
        }
        $dplyTailServices[] = 'syslog';
        if (isset($tags['mysql'])) {
            $dplyTailServices[] = 'mysql';
        }

        // Build ufw commands
        $ufwCommands = ['status', 'enable', 'disable', 'reload', 'reset'];
        $ufwActions = ['allow', 'deny', 'delete'];
        $ufwDefaults = ['allow', 'deny', 'reject', 'limit'];
        $ufwDirections = ['incoming', 'outgoing', 'routed'];

        // Build nginx commands
        $nginxCommands = ['-v', '-t', '-T', '-s'];
        $nginxSignals = ['reload', 'stop', 'quit', 'reopen'];

        // Build php-fpm test command
        $phpFpmUnit = $phpVersion !== null ? "php{$phpVersion}-fpm" : 'php-fpm';

        $sudoCommands = array_values(array_unique(array_merge(
            ['systemctl', 'ufw', 'nginx', 'service'],
            $tags['php'] ? [$phpFpmUnit] : []
        )));

        return [
            'systemctl' => [
                'positional' => [
                    1 => $verbs,
                    2 => $units,
                ],
            ],
            'service' => [
                // Older `service <name> <verb>` form. We don't know which order
                // the operator prefers, so suggest both via the same spec.
                'positional' => [
                    1 => $units,
                    2 => ['start', 'stop', 'restart', 'reload', 'status'],
                ],
            ],
            'tail' => [
                'positional' => [
                    1 => $logs,
                ],
            ],
            'less' => [
                'positional' => [
                    1 => $logs,
                ],
            ],
            'journalctl' => [
                'after_flag' => [
                    '-u' => $units,
                    '--unit' => $units,
                ],
            ],
            'dply' => [
                'positional' => [
                    1 => $dplySubcommands,
                    2 => array_values(array_unique(array_merge(
                        $dplyRestartServices,
                        $dplyTailServices,
                        ['list', 'show', 'run'] // site/recipe/ssl sub-subcommands
                    ))),
                ],
            ],
            'sudo' => [
                'positional' => [
                    1 => $sudoCommands,
                ],
                'after_flag' => [
                    'systemctl' => $sudoVerbs,
                ],
            ],
            'ufw' => [
                'positional' => [
                    1 => $ufwCommands,
                    2 => array_merge($ufwActions, $ufwDefaults, ['from', 'to']),
                ],
            ],
            'nginx' => [
                'positional' => [
                    1 => $nginxCommands,
                ],
                'after_flag' => [
                    '-s' => $nginxSignals,
                ],
            ],
        ];
    }

    /**
     * Installed systemd unit names for this server, in operator-relevant order.
     *
     * @return list<string>
     */
    protected static function unitsFor(Server $server): array
    {
        $tags = ServerInstalledServices::tagsFor($server);
        $phpVersion = ServerInstalledServices::phpVersionFor($server);
        $units = [];

        if (isset($tags['nginx'])) {
            $units[] = 'nginx';
        }
        if (isset($tags['apache'])) {
            $units[] = 'apache2';
        }
        if (isset($tags['caddy'])) {
            $units[] = 'caddy';
        }
        if (isset($tags['php'])) {
            $units[] = $phpVersion !== null ? "php{$phpVersion}-fpm" : 'php-fpm';
        }
        if (isset($tags['mysql'])) {
            $units[] = 'mysql';
            $units[] = 'mariadb';
        }
        if (isset($tags['postgres'])) {
            $units[] = 'postgresql';
        }
        if (isset($tags['redis'])) {
            $units[] = 'redis-server';
        }
        if (isset($tags['valkey'])) {
            $units[] = 'valkey-server';
        }
        if (isset($tags['memcached'])) {
            $units[] = 'memcached';
        }
        if (isset($tags['supervisor'])) {
            $units[] = 'supervisor';
        }
        if (isset($tags['docker'])) {
            $units[] = 'docker';
        }

        // Always-on system units worth typing.
        $units[] = 'cron';
        $units[] = 'ssh';
        $units[] = 'ufw';

        return array_values(array_unique($units));
    }

    /**
     * Known log paths for tail/less completion.
     *
     * @return list<string>
     */
    protected static function logPathsFor(Server $server): array
    {
        $tags = ServerInstalledServices::tagsFor($server);
        $phpVersion = ServerInstalledServices::phpVersionFor($server);
        $paths = ['/var/log/syslog', '/var/log/auth.log'];

        if (isset($tags['nginx'])) {
            $paths[] = '/var/log/nginx/error.log';
            $paths[] = '/var/log/nginx/access.log';
        }
        if (isset($tags['apache'])) {
            $paths[] = '/var/log/apache2/error.log';
            $paths[] = '/var/log/apache2/access.log';
        }
        if (isset($tags['caddy'])) {
            $paths[] = '/var/log/caddy/access.log';
        }
        if (isset($tags['php'])) {
            $paths[] = $phpVersion !== null
                ? "/var/log/php{$phpVersion}-fpm.log"
                : '/var/log/php-fpm.log';
        }
        if (isset($tags['mysql'])) {
            $paths[] = '/var/log/mysql/error.log';
        }
        if (isset($tags['postgres'])) {
            $paths[] = '/var/log/postgresql/';
        }
        if (isset($tags['redis'])) {
            $paths[] = '/var/log/redis/redis-server.log';
        }
        if (isset($tags['ufw'])) {
            $paths[] = '/var/log/ufw.log';
        }

        return array_values(array_unique($paths));
    }
}
