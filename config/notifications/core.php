<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | In-app inbox retention
    |--------------------------------------------------------------------------
    |
    | The per-user notification inbox grows unboundedly otherwise. Retention
    | hard-deletes inbox items that are READ and NOT saved, when they are either
    | older than `retention_days` OR beyond the newest `retention_keep_per_user`
    | for that user. Unread and saved items are NEVER pruned (saved is sacred).
    | Pruning the inbox row never touches the underlying NotificationEvent audit
    | trail. Run by PruneNotificationInboxItemsCommand (scheduled daily).
    |
    */

    'retention_days' => (int) env('NOTIFICATIONS_RETENTION_DAYS', 30),

    'retention_keep_per_user' => (int) env('NOTIFICATIONS_RETENTION_KEEP_PER_USER', 500),

];
