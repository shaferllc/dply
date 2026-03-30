<?php

namespace App\Support;

use App\Models\Server;
use App\Models\Site;

class NotificationSubscriptionRules
{
    public static function subscribableClassForEvent(string $eventKey): ?string
    {
        if (str_starts_with($eventKey, 'server.')) {
            return Server::class;
        }

        if (str_starts_with($eventKey, 'site.') || str_starts_with($eventKey, 'backup.')) {
            return Site::class;
        }

        return null;
    }

    public static function eventAppliesTo(string $eventKey, string $subscribableClass): bool
    {
        $expected = self::subscribableClassForEvent($eventKey);

        return $expected === $subscribableClass;
    }
}
