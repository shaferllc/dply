<?php

declare(strict_types=1);

namespace App\Services\DeployIntelligence\Rules;

use App\Models\DeployIntelligenceAlert;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteCertificate;
use App\Services\DeployIntelligence\AlertFinding;
use App\Services\DeployIntelligence\Contracts\IntelligenceRule;

/**
 * Flags TLS certificates that expire within the warning window. Mirrors
 * the differentiation-doc example "TLS expires in 7 days" but escalates
 * severity as the window shrinks.
 */
class TlsExpiringRule implements IntelligenceRule
{
    public const WARN_DAYS = 14;

    public const DANGER_DAYS = 3;

    public function key(): string
    {
        return DeployIntelligenceAlert::RULE_TLS_EXPIRING;
    }

    public function evaluate(Organization $organization): array
    {
        $siteIdQuery = Site::query()
            ->where('organization_id', $organization->id)
            ->orWhereIn('server_id', Server::query()->where('organization_id', $organization->id)->select('id'));

        $cutoff = now()->addDays(self::WARN_DAYS);

        $certs = SiteCertificate::query()
            ->whereIn('site_id', $siteIdQuery->select('id'))
            ->whereIn('status', [
                SiteCertificate::STATUS_ACTIVE,
                SiteCertificate::STATUS_ISSUED,
                SiteCertificate::STATUS_INSTALLING,
            ])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $cutoff)
            ->with('site:id,name')
            ->get(['id', 'site_id', 'status', 'provider_type', 'domains_json', 'expires_at']);

        $findings = [];
        $now = now();
        foreach ($certs as $cert) {
            if ($cert->expires_at === null) {
                continue;
            }
            $daysLeft = (int) floor($now->diffInSeconds($cert->expires_at, false) / 86400);
            $severity = $daysLeft <= self::DANGER_DAYS
                ? DeployIntelligenceAlert::SEVERITY_DANGER
                : DeployIntelligenceAlert::SEVERITY_WARNING;

            $siteName = $cert->site->name ?? '—';
            $findings[] = new AlertFinding(
                ruleKey: $this->key(),
                severity: $severity,
                signature: 'cert:'.$cert->id,
                title: $daysLeft <= 0
                    ? __('TLS expired on :site', ['site' => $siteName])
                    : __('TLS expires in :n days on :site', ['n' => max(0, $daysLeft), 'site' => $siteName]),
                summary: __('Provider :provider · expires :when', [
                    'provider' => $cert->provider_type ?? 'unknown',
                    'when' => $cert->expires_at->toDayDateTimeString(),
                ]),
                subject: $cert,
                payload: [
                    'site' => $siteName,
                    'site_id' => (string) $cert->site_id,
                    'certificate_id' => (string) $cert->id,
                    'expires_at' => $cert->expires_at->toIso8601String(),
                    'days_left' => $daysLeft,
                    'provider_type' => $cert->provider_type,
                ],
            );
        }

        return $findings;
    }
}
