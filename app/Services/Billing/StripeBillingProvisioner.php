<?php

namespace App\Services\Billing;

use Stripe\StripeClient;

/**
 * Idempotently creates the Stripe products and prices that back the Standard
 * plan committed in [[project_pricing_model]]. Looks objects up by
 * `metadata.dply_role` before creating; re-running is a no-op once all roles
 * are present, and rotates anything that's drifted (price amounts, product
 * names/descriptions, the parent product an existing price points at).
 *
 * Each Stripe Checkout line item displays its Product's name, so to keep the
 * invoice readable we use *separate Products* for each kind of line item:
 *
 *   - `dply base` — the $15/mo organization fee
 *   - `dply server — XS` … `XL` — the per-server tier fees
 *   - `dply Enterprise` — sales-led
 *
 * Old installs that ran an earlier version of this provisioner had a single
 * "dply Standard" product with multiple prices under it. The product-role
 * constant for the base is kept as `standard_product` (its original value)
 * so the metadata lookup matches — the human-facing name/description rotate
 * via {@see upsertProduct}'s drift handling.
 */
class StripeBillingProvisioner
{
    public const ROLE_BASE_PRODUCT = 'standard_product';

    public const ROLE_TIER_PRODUCT_PREFIX = 'standard_tier_product_';

    public const ROLE_BASE_MONTHLY = 'standard_base_monthly';

    public const ROLE_BASE_YEARLY = 'standard_base_yearly';

    public const ROLE_TIER_PREFIX = 'standard_tier_';

    public const ROLE_TIER_YEARLY_SUFFIX = '_yearly';

    public const ROLE_SERVERLESS_PRODUCT = 'standard_serverless_product';

    public const ROLE_SERVERLESS_MONTHLY = 'standard_serverless';

    public const ROLE_SERVERLESS_YEARLY = 'standard_serverless_yearly';

    public const ROLE_ENTERPRISE_PRODUCT = 'enterprise_product';

    private const TIER_PRODUCT_INFO = [
        'xs' => ['name' => 'dply server — XS', 'spec' => '≤1 vCPU · ≤2 GB'],
        's' => ['name' => 'dply server — S', 'spec' => '2 vCPU · ≤4 GB'],
        'm' => ['name' => 'dply server — M', 'spec' => '≤4 vCPU · ≤8 GB'],
        'l' => ['name' => 'dply server — L', 'spec' => '≤8 vCPU · ≤16 GB'],
        'xl' => ['name' => 'dply server — XL', 'spec' => 'Above L tier — capped'],
    ];

    public function __construct(private StripeClient $stripe) {}

    /**
     * @return array<string, string>
     */
    public function provision(): array
    {
        $result = [];

        // Base product — covers the org-level subscription fee. Existing
        // installs may have this product still named "dply Standard"; the
        // drift handler in upsertProduct will rename it to "dply base".
        $baseProduct = $this->upsertProduct(
            name: 'dply base',
            description: 'dply organization base fee. Covers your dply account and the platform features that come with it: command-center console, credentials, deploys, metrics, audit, team management.',
            role: self::ROLE_BASE_PRODUCT,
        );
        $result[self::ROLE_BASE_PRODUCT] = $baseProduct->id;

        // Tier products — one per size so each Checkout line item carries a
        // self-describing name. Created upfront so the price upserts below
        // can point at the right parent product.
        $tierProductIds = [];
        foreach (self::TIER_PRODUCT_INFO as $tierKey => $info) {
            $role = self::ROLE_TIER_PRODUCT_PREFIX.$tierKey;
            $product = $this->upsertProduct(
                name: $info['name'],
                description: 'Per-server fee, '.$info['spec'].'. Same fee whether you run on DigitalOcean, Hetzner, AWS, or your own SSH box — dply prices its own work, not your provider invoice.',
                role: $role,
            );
            $tierProductIds[$tierKey] = $product->id;
            $result[$role] = $product->id;
        }

        $standardConfig = (array) config('subscription.standard', []);
        $baseCents = (int) ($standardConfig['base_cents'] ?? 1500);
        $annualPct = (int) ($standardConfig['annual_discount_pct'] ?? 20);
        $tiers = (array) ($standardConfig['tiers'] ?? []);

        $result[self::ROLE_BASE_MONTHLY] = $this->upsertRecurringPrice(
            productId: $baseProduct->id,
            amount: $baseCents,
            interval: 'month',
            nickname: 'Base — Monthly',
            role: self::ROLE_BASE_MONTHLY,
        )->id;

        $result[self::ROLE_BASE_YEARLY] = $this->upsertRecurringPrice(
            productId: $baseProduct->id,
            amount: $this->annualAmount($baseCents, $annualPct),
            interval: 'year',
            nickname: 'Base — Yearly',
            role: self::ROLE_BASE_YEARLY,
        )->id;

        foreach (['xs', 's', 'm', 'l', 'xl'] as $tierKey) {
            $amount = (int) ($tiers[$tierKey] ?? 0);
            if ($amount <= 0) {
                continue;
            }

            $tierProductId = $tierProductIds[$tierKey] ?? null;
            if ($tierProductId === null) {
                continue;
            }

            $monthlyRole = self::ROLE_TIER_PREFIX.$tierKey;
            $result[$monthlyRole] = $this->upsertRecurringPrice(
                productId: $tierProductId,
                amount: $amount,
                interval: 'month',
                nickname: strtoupper($tierKey).' — Monthly',
                role: $monthlyRole,
            )->id;

            $yearlyRole = $monthlyRole.self::ROLE_TIER_YEARLY_SUFFIX;
            $result[$yearlyRole] = $this->upsertRecurringPrice(
                productId: $tierProductId,
                amount: $this->annualAmount($amount, $annualPct),
                interval: 'year',
                nickname: strtoupper($tierKey).' — Yearly',
                role: $yearlyRole,
            )->id;
        }

        // Serverless — a flat per-function fee. Its own product so the
        // invoice line reads "dply serverless function", not a server tier.
        $serverlessCents = (int) ($standardConfig['serverless_cents'] ?? 200);
        if ($serverlessCents > 0) {
            $serverlessProduct = $this->upsertProduct(
                name: 'dply serverless function',
                description: 'Per-function fee for serverless (FaaS) targets. Covers deploys, config, and console management for each function — billed per function, not per server.',
                role: self::ROLE_SERVERLESS_PRODUCT,
            );
            $result[self::ROLE_SERVERLESS_PRODUCT] = $serverlessProduct->id;

            $result[self::ROLE_SERVERLESS_MONTHLY] = $this->upsertRecurringPrice(
                productId: $serverlessProduct->id,
                amount: $serverlessCents,
                interval: 'month',
                nickname: 'Serverless function — Monthly',
                role: self::ROLE_SERVERLESS_MONTHLY,
            )->id;

            $result[self::ROLE_SERVERLESS_YEARLY] = $this->upsertRecurringPrice(
                productId: $serverlessProduct->id,
                amount: $this->annualAmount($serverlessCents, $annualPct),
                interval: 'year',
                nickname: 'Serverless function — Yearly',
                role: self::ROLE_SERVERLESS_YEARLY,
            )->id;
        }

        $enterpriseProduct = $this->upsertProduct(
            name: 'dply Enterprise',
            description: 'dply for larger fleets and procurement-led rollouts. Includes everything in Standard, plus volume pricing on per-server fees, SSO, audit log access, a custom MSA, dedicated support, and rollout planning. Pricing is negotiated per deal.',
            role: self::ROLE_ENTERPRISE_PRODUCT,
        );
        $result[self::ROLE_ENTERPRISE_PRODUCT] = $enterpriseProduct->id;

        return $result;
    }

    /**
     * Format a provisioning result map into copy-paste-ready .env lines.
     *
     * @param  array<string, string>  $result
     */
    public static function formatEnv(array $result): string
    {
        $map = [
            self::ROLE_BASE_MONTHLY => 'STRIPE_PRICE_STANDARD_BASE_MONTHLY',
            self::ROLE_BASE_YEARLY => 'STRIPE_PRICE_STANDARD_BASE_YEARLY',
            self::ROLE_TIER_PREFIX.'xs' => 'STRIPE_PRICE_STANDARD_TIER_XS',
            self::ROLE_TIER_PREFIX.'s' => 'STRIPE_PRICE_STANDARD_TIER_S',
            self::ROLE_TIER_PREFIX.'m' => 'STRIPE_PRICE_STANDARD_TIER_M',
            self::ROLE_TIER_PREFIX.'l' => 'STRIPE_PRICE_STANDARD_TIER_L',
            self::ROLE_TIER_PREFIX.'xl' => 'STRIPE_PRICE_STANDARD_TIER_XL',
            self::ROLE_TIER_PREFIX.'xs'.self::ROLE_TIER_YEARLY_SUFFIX => 'STRIPE_PRICE_STANDARD_TIER_XS_YEARLY',
            self::ROLE_TIER_PREFIX.'s'.self::ROLE_TIER_YEARLY_SUFFIX => 'STRIPE_PRICE_STANDARD_TIER_S_YEARLY',
            self::ROLE_TIER_PREFIX.'m'.self::ROLE_TIER_YEARLY_SUFFIX => 'STRIPE_PRICE_STANDARD_TIER_M_YEARLY',
            self::ROLE_TIER_PREFIX.'l'.self::ROLE_TIER_YEARLY_SUFFIX => 'STRIPE_PRICE_STANDARD_TIER_L_YEARLY',
            self::ROLE_TIER_PREFIX.'xl'.self::ROLE_TIER_YEARLY_SUFFIX => 'STRIPE_PRICE_STANDARD_TIER_XL_YEARLY',
            self::ROLE_SERVERLESS_MONTHLY => 'STRIPE_PRICE_STANDARD_SERVERLESS',
            self::ROLE_SERVERLESS_YEARLY => 'STRIPE_PRICE_STANDARD_SERVERLESS_YEARLY',
        ];

        $lines = [];
        foreach ($map as $role => $envVar) {
            if (isset($result[$role])) {
                $lines[] = $envVar.'='.$result[$role];
            }
        }

        return implode("\n", $lines);
    }

    private function annualAmount(int $monthlyCents, int $annualDiscountPct): int
    {
        return (int) round($monthlyCents * 12 * (100 - $annualDiscountPct) / 100);
    }

    /**
     * Look up the product by metadata role, create if missing, and patch the
     * stored name/description when they drift from what the code declares.
     * Marketing copy can change without spinning up a new Stripe product.
     */
    private function upsertProduct(string $name, string $description, string $role): \Stripe\Product
    {
        $existing = $this->stripe->products->search([
            'query' => sprintf('metadata[\'dply_role\']:\'%s\'', $role),
            'limit' => 1,
        ]);

        if (! empty($existing->data)) {
            $product = $existing->data[0];

            $updates = [];
            if (($product->name ?? '') !== $name) {
                $updates['name'] = $name;
            }
            if (($product->description ?? '') !== $description) {
                $updates['description'] = $description;
            }

            if ($updates !== []) {
                $product = $this->stripe->products->update($product->id, $updates);
            }

            return $product;
        }

        return $this->stripe->products->create([
            'name' => $name,
            'description' => $description,
            'metadata' => ['dply_role' => $role],
        ]);
    }

    /**
     * Look up the price by metadata role. Stripe Prices are *immutable* — if
     * the config amount no longer matches OR the price points at the wrong
     * parent product, the only correct move is to archive the old price and
     * create a new one. Existing subscriptions stay on the archived price
     * (Stripe allows that); future subscriptions land on the replacement.
     */
    private function upsertRecurringPrice(
        string $productId,
        int $amount,
        string $interval,
        string $nickname,
        string $role,
    ): \Stripe\Price {
        $existing = $this->stripe->prices->search([
            'query' => sprintf('metadata[\'dply_role\']:\'%s\' AND active:\'true\'', $role),
            'limit' => 1,
        ]);

        if (! empty($existing->data)) {
            $current = $existing->data[0];

            $sameAmount = (int) ($current->unit_amount ?? 0) === $amount;
            $sameInterval = ($current->recurring->interval ?? null) === $interval;
            $sameProduct = (string) ($current->product ?? '') === $productId;

            if ($sameAmount && $sameInterval && $sameProduct) {
                return $current;
            }

            // Drift detected — archive the stale price, fall through to
            // create a replacement under the right product at the right
            // amount.
            $this->stripe->prices->update($current->id, ['active' => false]);
        }

        return $this->stripe->prices->create([
            'product' => $productId,
            'unit_amount' => $amount,
            'currency' => 'usd',
            'recurring' => ['interval' => $interval],
            'nickname' => $nickname,
            'metadata' => ['dply_role' => $role],
        ]);
    }
}
