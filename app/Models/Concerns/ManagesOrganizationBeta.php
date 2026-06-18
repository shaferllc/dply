<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\Beta\BetaProgram;
use Illuminate\Support\Carbon;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 *
 * @property ?Carbon $beta_joined_at
 */
trait ManagesOrganizationBeta
{
    /**
     * True while this org is an active closed-beta participant: it redeemed an
     * invite (`beta_joined_at` set) AND the global beta program is still open.
     * After the cutover this flips false and the org rejoins the normal
     * plan/trial lifecycle.
     */
    public function isBeta(): bool
    {
        return data_get($this->getAttributes(), 'beta_joined_at') !== null && BetaProgram::isOpen();
    }

    /**
     * True when the dply platform fee is waived this cycle: an active beta org
     * that has NOT subscribed early. Opting into a paid plan turns the waiver
     * off (the org wanted to pay) — but the free CX22 stays comped regardless,
     * via the comped_until column. Drives the $0 plan price in billing.
     */
    public function betaFeeWaived(): bool
    {
        return $this->isBeta() && ! $this->onAnyPaidPlan();
    }

    /**
     * BYO server ceiling for a beta org — generous enough to feel unlimited for
     * a solo dev / small team, bounded so a leaked invite can't provision
     * hundreds of boxes on a stolen cloud key via dply.
     */
    public function betaByoServerLimit(): int
    {
        return max(1, (int) config('subscription.standard.beta.byo_servers', 5));
    }

    /**
     * Free dply-managed server ceiling for a beta org — the single free CX22.
     */
    public function betaManagedServerLimit(): int
    {
        return max(0, (int) config('subscription.standard.beta.managed_servers', 1));
    }
}
