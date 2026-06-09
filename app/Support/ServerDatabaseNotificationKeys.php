<?php

namespace App\Support;

final class ServerDatabaseNotificationKeys
{
    /** @var list<string> */
    public const KINDS = ['created', 'removed', 'engine_installed', 'engine_removed', 'user_created', 'user_removed', 'credential_shared'];

    public static function eventKey(string $kind): string
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Invalid database notify kind.');
        }

        return 'server.database.'.$kind;
    }

    /**
     * @return list<string>
     */
    public static function eventKeys(): array
    {
        return array_map(static fn (string $kind) => self::eventKey($kind), self::KINDS);
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
