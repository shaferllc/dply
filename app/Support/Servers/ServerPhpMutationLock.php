<?php

namespace App\Support\Servers;

use App\Models\Server;
use Illuminate\Support\Facades\Cache;

/**
 * Single-server mutex for PHP package actions and config saves.
 */
final class ServerPhpMutationLock
{
    public static function key(Server $server): string
    {
        return 'server-php-package-action:'.$server->id;
    }

    public static function isHeld(Server $server): bool
    {
        return Cache::lock(self::key($server), 1)->isLocked();
    }

    public static function acquire(Server $server, int $seconds)
    {
        return Cache::lock(self::key($server), $seconds);
    }

    public static function releaseIfOwned($lock, bool $acquired): void
    {
        if ($acquired) {
            $lock->release();
        }
    }
}
