<?php

namespace App\Support;

final class ServerDatabaseNotificationKeys
{
    /** @var list<string> */
    public const KINDS = ['created', 'removed'];

    public static function eventKey(string $kind): string
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Invalid database notify kind.');
        }

        return 'server.database.'.$kind;
    }

    /**
     * @return array<string, string>
     */
    public static function kindLabels(): array
    {
        return [
            'created' => __('Created'),
            'removed' => __('Removed'),
        ];
    }
}
