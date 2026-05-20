<?php

namespace App\Enums;

/**
 * The discrete states an organization can occupy on the subscription lifecycle.
 * Drives the day-15 soft pause and day-45 hard pause behavior committed in
 * project_pricing_model.
 *
 * - {@see ActiveTrial}: trial_ends_at is in the future, no Stripe sub. Full access.
 * - {@see ExpiredSoft}: trial expired, no Stripe sub, within soft-pause window
 *   (default 30 days post-expiry). Deploys + scheduler runs are blocked; UI
 *   stays usable so a card-add unfreezes everything immediately.
 * - {@see ExpiredHard}: trial expired beyond the soft-pause window. Agent
 *   disconnects, config persists, customer can revive by adding a card.
 * - {@see Subscribed}: any active paid plan (Standard, Enterprise, legacy Pro).
 *   Full access.
 * - {@see NoTrial}: no trial_ends_at recorded — legacy orgs from before the
 *   pricing redesign, or orgs explicitly excluded from trial tracking.
 */
enum TrialState: string
{
    case ActiveTrial = 'active_trial';
    case ExpiredSoft = 'expired_soft';
    case ExpiredHard = 'expired_hard';
    case Subscribed = 'subscribed';
    case NoTrial = 'no_trial';

    /**
     * True when the state should prevent cash-burning operations (deploys,
     * scheduler runs). Subscribed orgs always pass; expired-trial states fail.
     */
    public function permitsBilledWork(): bool
    {
        return match ($this) {
            self::Subscribed, self::ActiveTrial, self::NoTrial => true,
            self::ExpiredSoft, self::ExpiredHard => false,
        };
    }
}
