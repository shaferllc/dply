<?php

namespace App\Support;

/**
 * Browser-facing Reverb port (Echo / Pusher-js).
 *
 * @see config/broadcasting.php
 * @see config/reverb.php (application options)
 */
final class ReverbClientConfig
{
    /**
     * Resolve the WebSocket port for the Laravel app and Reverb server config.
     *
     * @param  string|null  $explicitPort  From REVERB_PORT when set in the environment.
     * @param  string|null  $serverPort  From REVERB_SERVER_PORT (Reverb bind port).
     */
    public static function browserPort(
        ?string $explicitPort,
        string $scheme,
        ?string $serverPort,
        string $appEnvironment,
    ): int {
        if ($explicitPort !== null && $explicitPort !== '') {
            return (int) $explicitPort;
        }

        $scheme = strtolower($scheme !== '' ? $scheme : 'http');
        $server = (int) (($serverPort !== null && $serverPort !== '') ? $serverPort : '8080');

        if ($scheme === 'https') {
            return $appEnvironment === 'production' ? 443 : $server;
        }

        return $server;
    }
}
