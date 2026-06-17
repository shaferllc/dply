<?php

declare(strict_types=1);

namespace App\Services\RemoteCli;

use App\Models\Site;
use App\Models\SiteAuditEvent;
use App\Models\User;

/**
 * Single entry point for writing SiteAuditEvent rows.
 *
 * Centralized so PR 9+ surfaces (Plugins tab, Hardening tab, Snapshots
 * tab, scaffold pipeline) all use the same shape and field validation.
 * Reads (RiskLevel::Read) are filtered out — they would explode row
 * counts with no investigative value (Q17).
 */
class SiteAuditWriter
{
    /**
     * @param  array<string, mixed> $payload
     */
    public function record(
        Site $site,
        ?User $user,
        string $action,
        RiskLevel $risk,
        string $transport,
        string $summary,
        array $payload = [],
        string $resultStatus = SiteAuditEvent::RESULT_SUCCESS,
    ): ?SiteAuditEvent {
        // Read-only commands aren't audited; only mutating + destructive
        // settled events. Tinker is the exception — it's runtime-input
        // and gets classified as Destructive elsewhere, so it lands here.
        if ($risk === RiskLevel::Read) {
            return null;
        }

        return SiteAuditEvent::query()->create([
            'site_id' => $site->getKey(),
            'user_id' => $user?->getKey(),
            'action' => $action,
            'risk' => $risk,
            'transport' => $transport,
            'summary' => mb_substr($summary, 0, 500),
            'payload' => $payload,
            'result_status' => $resultStatus,
        ]);
    }
}
