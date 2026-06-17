<?php

namespace App\Support\Servers;

use App\Models\Server;
use Illuminate\Contracts\Cache\Lock;
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
        $lock = Cache::lock(self::key($server), 1);
        if ($lock->get()) {
            $lock->release();

            return false;
        }

        return true;
    }

    public static function acquire(Server $server, int $seconds): Lock
    {
        return Cache::lock(self::key($server), $seconds);
    }

    public static function releaseIfOwned(Lock $lock, bool $acquired): void
    {
        if ($acquired) {
            $lock->release();
        }
    }
}
