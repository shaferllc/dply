<?php

declare(strict_types=1);

namespace App\Services\DeployIntelligence;

use App\Models\DeployIntelligenceAlert;
use App\Models\NotificationChannel;
use App\Models\Organization;
use App\Services\DeployIntelligence\Contracts\IntelligenceRule;
use App\Services\DeployIntelligence\Rules\EnvDriftRule;
use App\Services\DeployIntelligence\Rules\SlowBuildRule;
use App\Services\DeployIntelligence\Rules\TlsExpiringRule;

/**
 * Runs every {@see IntelligenceRule} against an organization, upserts
 * findings into deploy_intelligence_alerts, resolves alerts the
 * scanner no longer observes, and fires notification-channel pings
 * for newly-opened alerts.
 *
 * Upsert keys are (organization_id, rule_key, signature) — a rule
 * keeping a stable signature across scans keeps one row; varying the
 * signature opens a new alert. Dismissed alerts that the rule still
 * sees remain dismissed (we never auto-reopen).
 */
class Scanner
{
    /** @var list<IntelligenceRule> */
    private array $rules;

    /**
     * @param  list<IntelligenceRule>|null  $rules  override for tests; default = the canonical set
     */
    public function __construct(?array $rules = null)
    {
        $this->rules = $rules ?? [
            new SlowBuildRule,
            new TlsExpiringRule,
            new EnvDriftRule,
        ];
    }

    /**
     * @return array{opened:int, refreshed:int, resolved:int}
     */
    public function scan(Organization $organization): array
    {
        $opened = 0;
        $refreshed = 0;
        $resolved = 0;
        $now = now();
        $newlyOpenedAlerts = [];

        foreach ($this->rules as $rule) {
            $findings = $rule->evaluate($organization);
            $observedSignatures = [];

            foreach ($findings as $finding) {
                $payload = $finding->toArray();
                $observedSignatures[] = $payload['signature'];

                $existing = DeployIntelligenceAlert::query()
                    ->where('organization_id', $organization->id)
                    ->where('rule_key', $rule->key())
                    ->where('signature', $payload['signature'])
                    ->first();

                if ($existing === null) {
                    $alert = DeployIntelligenceAlert::query()->create([
                        'organization_id' => $organization->id,
                        'rule_key' => $payload['rule_key'],
                        'severity' => $payload['severity'],
                        'signature' => $payload['signature'],
                        'subject_type' => $payload['subject_type'],
                        'subject_id' => $payload['subject_id'],
                        'title' => $payload['title'],
                        'summary' => $payload['summary'],
                        'payload' => $payload['payload'],
                        'first_observed_at' => $now,
                        'last_observed_at' => $now,
                    ]);
                    $opened++;
                    $newlyOpenedAlerts[] = $alert;

                    continue;
                }

                $existing->fill([
                    'severity' => $payload['severity'],
                    'title' => $payload['title'],
                    'summary' => $payload['summary'],
                    'payload' => $payload['payload'],
                    'last_observed_at' => $now,
                ]);

                // Re-opening: rule sees the condition again after a
                // resolve. Dismissed alerts stay dismissed.
                if ($existing->resolved_at !== null && $existing->dismissed_at === null) {
                    $existing->resolved_at = null;
                    $existing->first_observed_at = $now;
                    $opened++;
                    $newlyOpenedAlerts[] = $existing;
                } else {
                    $refreshed++;
                }

                $existing->save();
            }

            // Anything previously open for this rule and not seen this
            // pass is considered resolved by the scanner.
            $resolved += DeployIntelligenceAlert::query()
                ->where('organization_id', $organization->id)
                ->where('rule_key', $rule->key())
                ->whereNull('resolved_at')
                ->whereNull('dismissed_at')
                ->when(
                    $observedSignatures !== [],
                    fn ($q) => $q->whereNotIn('signature', $observedSignatures),
                )
                ->update(['resolved_at' => $now]);
        }

        foreach ($newlyOpenedAlerts as $alert) {
            $this->notifyOrgChannels($organization, $alert);
        }

        return [
            'opened' => $opened,
            'refreshed' => $refreshed,
            'resolved' => $resolved,
        ];
    }

    private function notifyOrgChannels(Organization $organization, DeployIntelligenceAlert $alert): void
    {
        // Org-wide channels (no per-event subscription required). Each
        // organisation may not have any — that's fine, alerts still
        // land in the UI surface.
        $channels = NotificationChannel::query()
            ->where('owner_type', $organization->getMorphClass())
            ->where('owner_id', $organization->id)
            ->get();

        if ($channels->isEmpty()) {
            return;
        }

        $subject = '['.strtoupper($alert->severity).'] '.$alert->title;
        $body = (string) ($alert->summary ?? '');

        foreach ($channels as $channel) {
            try {
                $channel->sendOperationalMessage(
                    subject: $subject,
                    text: $body,
                    actionUrl: null,
                    actionLabel: null,
                );
            } catch (\Throwable $e) {
                // sendOperationalMessage already logs failures; we
                // intentionally don't bubble so one bad channel
                // doesn't block other channels in the loop.
            }
        }
    }
}
