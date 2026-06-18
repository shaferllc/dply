<?php

declare(strict_types=1);

namespace App\Modules\Logs\Services;

use App\Models\Organization;
use App\Models\Server;
use App\Models\ServerLogUsageDaily;
use Illuminate\Support\Carbon;

/**
 * Builds the small per-org policy table the aggregator reads as a Vector
 * enrichment table (CSV): `org_id → {retention_days, allowed}`. Lets one shared
 * ClickHouse table carry per-tier retention (stamped at insert) and lets the
 * aggregator drop a hard-capped org's events before they cost anything.
 *
 * Kept TINY — only orgs that differ from the free-MVP default appear; every
 * absent org gets the aggregator's built-in default (fail open). The pure
 * row/CSV builders are static so they can be unit-tested without a DB.
 *
 * Refreshed + shipped to the box by {@see \App\Jobs\SyncLogAggregatorPolicyJob}.
 * See docs/SERVER_LOGS_BILLING.md §3.2.
 */
class ServerLogAggregatorPolicyMap
{
    public const HEADER = 'org_id,retention_days,allowed';

    public function __construct(private ServerLogEntitlements $entitlements) {}

    /**
     * @return list<array{org_id: string, retention_days: int, allowed: bool}>
     */
    public function rows(): array
    {
        $default = $this->defaultRetentionDays();
        [$start, $end] = $this->monthWindow();

        $bytesByOrg = ServerLogUsageDaily::query()
            ->whereBetween('day', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('organization_id, SUM(bytes) AS bytes')
            ->groupBy('organization_id')
            ->pluck('bytes', 'organization_id');

        // Orgs that ship logs (have an agent) OR billed any volume this month.
        $shippingOrgIds = Server::query()
            ->whereHas('logAgent')
            ->whereNotNull('organization_id')
            ->distinct()
            ->pluck('organization_id');

        $orgIds = $shippingOrgIds
            ->merge($bytesByOrg->keys())
            ->unique()
            ->values();

        if ($orgIds->isEmpty()) {
            return [];
        }

        $rows = [];
        foreach (Organization::query()->whereIn('id', $orgIds->all())->get() as $org) {
            $entitlement = $this->entitlements->forOrganization($org);
            $monthBytes = (int) ($bytesByOrg[$org->id] ?? 0);

            $row = self::buildRow($org->id, $entitlement, $monthBytes, $default);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    public function toCsv(): string
    {
        return self::renderCsv($this->rows());
    }

    /**
     * Pure row decision: emit a row only when the org differs from the default
     * (non-default retention OR hard-capped) so the file stays tiny. Returns null
     * for a default-retention, uncapped org (the aggregator default covers it).
     *
     * @return array{org_id: string, retention_days: int, allowed: bool}|null
     */
    public static function buildRow(
        string $orgId,
        ServerLogEntitlement $entitlement,
        int $monthBytes,
        int $defaultRetentionDays,
    ): ?array {
        $retention = max(1, $entitlement->retentionDays);
        $allowed = ! $entitlement->isHardCapped($monthBytes);

        if ($retention === $defaultRetentionDays && $allowed) {
            return null;
        }

        return [
            'org_id' => $orgId,
            'retention_days' => $retention,
            'allowed' => $allowed,
        ];
    }

    /**
     * @param  list<array{org_id: string, retention_days: int, allowed: bool}>  $rows
     */
    public static function renderCsv(array $rows): string
    {
        $lines = [self::HEADER];
        foreach ($rows as $row) {
            $lines[] = sprintf(
                '%s,%d,%s',
                $row['org_id'],
                $row['retention_days'],
                $row['allowed'] ? 'true' : 'false',
            );
        }

        return implode("\n", $lines)."\n";
    }

    public function defaultRetentionDays(): int
    {
        return max(1, (int) config('server_logs.clickhouse.retention_days', 7));
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function monthWindow(): array
    {
        return [now()->startOfMonth(), now()];
    }
}
