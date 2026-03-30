<?php

namespace App\Services\Referrals;

use App\Models\Organization;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReferralStripeCredit
{
    /**
     * Apply a customer balance credit (cents) to the organization’s Stripe customer.
     *
     * @return non-empty-string|null Stripe balance transaction id
     */
    public function apply(Organization $organization, int $creditCents): ?string
    {
        if ($creditCents < 1) {
            return null;
        }

        $stripeId = $organization->stripe_id;
        if (! is_string($stripeId) || $stripeId === '') {
            return null;
        }

        $currency = strtolower((string) (config('cashier.currency') ?? 'usd'));

        try {
            $tx = $organization->stripe()->customers->createBalanceTransaction(
                $stripeId,
                [
                    'amount' => -$creditCents,
                    'currency' => $currency,
                    'description' => config('app.name').' referral bonus',
                ]
            );

            $id = $tx->id ?? null;

            return is_string($id) && $id !== '' ? $id : null;
        } catch (Throwable $e) {
            Log::warning('referral.stripe_credit_failed', [
                'organization_id' => $organization->id,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
