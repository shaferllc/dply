<?php

namespace App\Services\Billing;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Throwable;

/**
 * Best-effort sync of profile VAT to the current organization's Stripe Customer tax IDs.
 */
class StripeOrganizationTaxIdSync
{
    /**
     * @return list<string>
     */
    public function syncFromProfile(User $user): array
    {
        $organization = $user->currentOrganization();
        if (! $organization instanceof Organization) {
            return [];
        }

        if (! Gate::forUser($user)->allows('update', $organization)) {
            return [];
        }

        if (! $organization->hasStripeId()) {
            return [];
        }

        $normalized = $this->normalizeVat($user->vat_number);
        /** @var StripeClient $stripe */
        $stripe = Organization::stripe();
        $customerId = (string) $organization->stripe_id;

        try {
            $this->removeStripeEuGbTaxIds($stripe, $customerId);

            if ($normalized === '') {
                return [];
            }

            $payload = $this->resolveStripeTaxPayload($normalized);
            if ($payload === null) {
                return [];
            }

            $stripe->customers->createTaxId($customerId, [
                'type' => $payload['type'],
                'value' => $payload['value'],
            ]);
        } catch (ApiErrorException $e) {
            Log::notice('vat.stripe_tax_id_sync_failed', [
                'organization_id' => $organization->id,
                'stripe_code' => $e->getStripeCode(),
                'message' => $e->getMessage(),
            ]);

            return [
                __('Stripe could not update your tax ID on file. Your VAT number was saved in Dply; you can add it in the Stripe customer billing portal if needed.'),
            ];
        } catch (Throwable $e) {
            Log::notice('vat.stripe_tax_id_sync_failed', [
                'organization_id' => $organization->id,
                'message' => $e->getMessage(),
            ]);

            return [
                __('Stripe could not update your tax ID on file. Your VAT number was saved in Dply.'),
            ];
        }

        return [];
    }

    private function normalizeVat(?string $vat): string
    {
        if ($vat === null || $vat === '') {
            return '';
        }

        return strtoupper(preg_replace('/[\s.\-\x{00A0}]+/u', '', $vat) ?? '');
    }

    private function removeStripeEuGbTaxIds(StripeClient $stripe, string $customerId): void
    {
        $taxIds = $stripe->customers->allTaxIds($customerId, ['limit' => 100]);
        foreach ($taxIds->data as $taxId) {
            if (! in_array($taxId->type, ['eu_vat', 'gb_vat'], true)) {
                continue;
            }
            try {
                $stripe->customers->deleteTaxId($customerId, $taxId->id);
            } catch (Throwable $e) {
                Log::notice('vat.stripe_tax_id_delete_failed', [
                    'customer' => $customerId,
                    'tax_id' => $taxId->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @return array{type: string, value: string}|null
     */
    private function resolveStripeTaxPayload(string $normalized): ?array
    {
        if (strlen($normalized) < 4) {
            return null;
        }

        $prefix = substr($normalized, 0, 2);
        $euPrefixes = config('vat.stripe_eu_vat_prefixes', []);

        if ($prefix === 'GB') {
            return ['type' => 'gb_vat', 'value' => $normalized];
        }

        if ($prefix === 'GR') {
            $normalized = 'EL'.substr($normalized, 2);
            $prefix = 'EL';
        }

        if (in_array($prefix, $euPrefixes, true)) {
            return ['type' => 'eu_vat', 'value' => $normalized];
        }

        return null;
    }
}
