<?php

declare(strict_types=1);

namespace App\Support\Sites;

use Illuminate\Support\Collection;

final class SiteIndexSummary
{
    /**
     * @param  Collection<int, SiteIndexRow>  $rows
     * @return array{total: int, active: int, provisioning: int, attention: int, secured: int, servers: int}
     */
    public static function fromRows(Collection $rows): array
    {
        return [
            'total' => $rows->count(),
            'active' => $rows->filter(fn (SiteIndexRow $r): bool => $r->isReadyForTraffic)->count(),
            'provisioning' => $rows->filter(fn (SiteIndexRow $r): bool => $r->isProvisioning)->count(),
            'attention' => $rows->filter(fn (SiteIndexRow $r): bool => $r->isFailed)->count(),
            'secured' => $rows->filter(fn (SiteIndexRow $r): bool => $r->isSecured)->count(),
            'servers' => $rows->pluck('serverId')->filter()->unique()->count(),
        ];
    }
}
