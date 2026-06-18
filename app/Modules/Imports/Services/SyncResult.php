<?php

declare(strict_types=1);

namespace App\Modules\Imports\Services;

use Illuminate\Support\Carbon;

/**
 * Result of a sync run — what to show in the toast / inventory header.
 * Shared between Ploi and Forge inventory syncs.
 */
final class SyncResult
{
    public function __construct(
        public readonly int $serversSeen,
        public readonly int $sitesSeen,
        public readonly Carbon $syncedAt,
    ) {}
}
