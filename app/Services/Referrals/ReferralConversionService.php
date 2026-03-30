<?php

namespace App\Services\Referrals;

use App\Models\Organization;
use App\Models\ReferralReward;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReferralConversionService
{
    public function __construct(
        private ReferralStripeCredit $stripeCredit,
    ) {}

    /**
     * Handle Stripe invoice.payment_succeeded webhooks for referral rewards.
     */
    public function handleInvoicePaymentSucceeded(array $payload): void
    {
        $invoice = $payload['data']['object'] ?? [];
        if (! is_array($invoice)) {
            return;
        }

        if (($invoice['amount_paid'] ?? 0) < 1) {
            return;
        }

        $customerId = $invoice['customer'] ?? null;
        if (! is_string($customerId) || $customerId === '') {
            return;
        }

        if (empty($invoice['subscription'])) {
            return;
        }

        if (! $this->invoiceContainsProPrice($invoice)) {
            return;
        }

        $organization = Organization::query()->where('stripe_id', $customerId)->first();
        if (! $organization) {
            return;
        }

        $candidates = $organization->users()
            ->whereNotNull('users.referred_by_user_id')
            ->whereNull('users.referral_converted_at')
            ->get();

        foreach ($candidates as $user) {
            $this->convertOne($user);
        }
    }

    protected function invoiceContainsProPrice(array $invoice): bool
    {
        $proPriceIds = array_values(array_filter([
            (string) (config('subscription.plans.pro_monthly.price_id') ?? ''),
            (string) (config('subscription.plans.pro_yearly.price_id') ?? ''),
        ]));

        foreach ($invoice['lines']['data'] ?? [] as $line) {
            if (! is_array($line)) {
                continue;
            }
            $priceId = $line['price']['id'] ?? null;
            if (! is_string($priceId) || $priceId === '') {
                continue;
            }
            if ($proPriceIds === []) {
                if (($line['type'] ?? '') === 'subscription') {
                    return true;
                }

                continue;
            }
            if (in_array($priceId, $proPriceIds, true)) {
                return true;
            }
        }

        return false;
    }

    protected function convertOne(User $referredUser): void
    {
        $referrerId = $referredUser->referred_by_user_id;
        if (! $referrerId) {
            return;
        }

        $bonusCents = max(0, (int) config('referral.bonus_credit_cents', 0));

        DB::transaction(function () use ($referredUser, $referrerId, $bonusCents): void {
            $locked = User::query()->whereKey($referredUser->id)->lockForUpdate()->first();
            if (! $locked || $locked->referral_converted_at !== null) {
                return;
            }

            $referrer = User::query()->whereKey($referrerId)->lockForUpdate()->first();
            if (! $referrer) {
                return;
            }

            $locked->forceFill(['referral_converted_at' => now()])->save();

            if (ReferralReward::query()->where('referred_user_id', $locked->id)->exists()) {
                return;
            }

            $referrerOrg = $this->firstBillableOrganizationForReferrer($referrer);
            $stripeTxId = null;
            if ($bonusCents > 0 && $referrerOrg) {
                $stripeTxId = $this->stripeCredit->apply($referrerOrg, $bonusCents);
            }

            ReferralReward::query()->create([
                'referrer_user_id' => $referrer->id,
                'referred_user_id' => $locked->id,
                'referrer_organization_id' => $referrerOrg?->id,
                'bonus_credit_cents' => $bonusCents,
                'stripe_balance_transaction_id' => $stripeTxId,
            ]);
        });
    }

    protected function firstBillableOrganizationForReferrer(User $referrer): ?Organization
    {
        return $referrer->organizations()
            ->whereNotNull('organizations.stripe_id')
            ->where('organizations.stripe_id', '!=', '')
            ->orderBy('organizations.id')
            ->first();
    }
}
