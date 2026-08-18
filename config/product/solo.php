<?php

use SoloTerm\Solo\Commands\Command;
use SoloTerm\Solo\Commands\MakeCommand;
use SoloTerm\Solo\Commands\TestCommand;
use SoloTerm\Solo\Hotkeys;
use SoloTerm\Solo\Themes;

// Solo may not (should not!) exist in prod, so we have to
// check here first to see if it's installed.
if (! class_exists('\SoloTerm\Solo\Manager')) {
    return [
        //
    ];
}

$commands = [
    'About' => 'php artisan solo:about',
    // For enhanced log viewing with vendor frame collapsing, see soloterm/vtail
    'Logs' => 'tail -f -n 100 '.storage_path('logs/laravel.log'),
    'Vite' => 'npm run dev',
    'Make' => new MakeCommand,
    // 'HTTP' => 'php artisan serve',

    // Lazy commands do not automatically start when Solo starts.
    'Dumps' => Command::from('php artisan solo:dumps')->lazy(),
    'Reverb' => Command::from('php artisan reverb:start --debug')->lazy(),
    'Pint' => Command::from('./vendor/bin/pint --ansi')->lazy(),
    /*
     * Redis queues + Horizon (set QUEUE_CONNECTION=redis; run redis-server). Uses Horizon's
     * built-in file watcher so code/config changes restart Horizon automatically in local dev.
     * Use “Queue” for database-backed workers without Redis.
     */
    'Horizon' => Command::from('php artisan horizon:listen')->lazy(),
    'Queue' => Command::from('php artisan queue:work')->lazy(),
    /*
     * Beyond Code Expose — uses project binary (beyondcode/expose) so Solo’s shell does not
     * need a global `expose` on PATH. Override with SOLO_EXPOSE_COMMAND=expose if yours is global.
     */
    'Expose' => Command::from(
        (static function (): string {
            $share = escapeshellarg((string) env('SOLO_EXPOSE_SHARE_URL', 'https://dplyi.test'));
            $custom = env('SOLO_EXPOSE_COMMAND');
            if (is_string($custom) && trim($custom) !== '') {
                return trim($custom).' share '.$share;
            }

            return escapeshellarg(\PHP_BINARY).' '.escapeshellarg(base_path('vendor/bin/expose')).' share '.$share;
        })()
    )->lazy(),
    'Tests' => TestCommand::artisan(),
];

/*
 * Optional local Jetty (or any shell) used as tunnel target for inbound HTTPS callbacks; proxy to Laravel.
 * Set JETTY_START_COMMAND in .env (see .env.example). Lazy = does not auto-start when Solo opens.
 */
if (filled(env('JETTY_START_COMMAND'))) {
    $commands['Jetty'] = Command::from((string) env('JETTY_START_COMMAND'))->lazy();
}

return [
    /*
    |--------------------------------------------------------------------------
    | Themes
    |--------------------------------------------------------------------------
    */
    'theme' => env('SOLO_THEME', 'dark'),

    'themes' => [
        'light' => Themes\LightTheme::class,
        'dark' => Themes\DarkTheme::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Keybindings
    |--------------------------------------------------------------------------
    */
    'keybinding' => env('SOLO_KEYBINDING', 'default'),

    'keybindings' => [
        'default' => Hotkeys\DefaultHotkeys::class,
        'vim' => Hotkeys\VimHotkeys::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Commands
    |--------------------------------------------------------------------------
    */
    'commands' => $commands,

    /*
    |--------------------------------------------------------------------------
    | Miscellaneous
    |--------------------------------------------------------------------------
    */

    /*
     * If you run the solo:dumps command, Solo will start a server to receive
     * the dumps. This is the address. You probably don't need to change
     * this unless the default is already taken for some reason.
     */
    'dump_server_host' => env('SOLO_DUMP_SERVER_HOST', 'tcp://127.0.0.1:9984'),
];
