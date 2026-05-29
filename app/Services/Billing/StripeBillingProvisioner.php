<?php

namespace App\Services\Billing;

use Stripe\Price;
use Stripe\Product;
use Stripe\StripeClient;

/**
 * Idempotently creates the Stripe products and prices that back the flat
 * plan model in docs/PRICING_AND_REVENUE.md. Looks objects up by
 * `metadata.dply_role` before creating; re-running is a no-op once all roles
 * are present, and rotates anything that's drifted (price amounts, product
 * names/descriptions, the parent product an existing price points at).
 *
 * Each Stripe Checkout line item displays its Product's name, so to keep the
 * invoice readable we use *separate Products* for each kind of line item:
 *
 *   - `dply Starter` / `dply Pro` / `dply Business` — the flat plan fees,
 *     metered by BYO server count (Free has no Stripe object — a $0 plan
 *     never creates a subscription)
 *   - `dply Cloud app` / `dply Edge site` / `dply serverless function` —
 *     managed products billed a la carte
 *   - `dply Enterprise` — sales-led
 */
class StripeBillingProvisioner
{
    public const ROLE_PLAN_PRODUCT_PREFIX = 'standard_plan_product_';

    public const ROLE_PLAN_PREFIX = 'standard_plan_';

    public const ROLE_PLAN_YEARLY_SUFFIX = '_yearly';

    public const ROLE_SERVERLESS_PRODUCT = 'standard_serverless_product';

    public const ROLE_SERVERLESS_MONTHLY = 'standard_serverless';

    public const ROLE_SERVERLESS_YEARLY = 'standard_serverless_yearly';

    public const ROLE_SERVERLESS_USAGE_PRODUCT = 'standard_serverless_usage_product';

    public const ROLE_SERVERLESS_USAGE_MONTHLY = 'standard_serverless_usage';

    public const ROLE_MANAGED_SERVER_PRODUCT = 'standard_managed_server_product';

    public const ROLE_MANAGED_SERVER_MONTHLY = 'standard_managed_server';

    public const ROLE_CLOUD_PRODUCT = 'standard_cloud_product';

    public const ROLE_CLOUD_MONTHLY = 'standard_cloud';

    public const ROLE_CLOUD_YEARLY = 'standard_cloud_yearly';

    public const ROLE_CLOUD_USAGE_PRODUCT = 'standard_cloud_usage_product';

    public const ROLE_CLOUD_USAGE_MONTHLY = 'standard_cloud_usage';

    public const ROLE_EDGE_PRODUCT = 'standard_edge_product';

    public const ROLE_EDGE_MONTHLY = 'standard_edge';

    public const ROLE_EDGE_YEARLY = 'standard_edge_yearly';

    public const ROLE_EDGE_USAGE_PRODUCT = 'standard_edge_usage_product';

    public const ROLE_EDGE_USAGE_MONTHLY = 'standard_edge_usage';

    public const ROLE_ENTERPRISE_PRODUCT = 'enterprise_product';

    public function __construct(private StripeClient $stripe) {}

    /**
     * @return array<string, string>
     */
    public function provision(): array
    {
        $result = [];

        $standardConfig = (array) config('subscription.standard', []);
        $annualPct = (int) ($standardConfig['annual_discount_pct'] ?? 20);
        $plans = (array) ($standardConfig['plans'] ?? []);

        // One product + monthly/yearly price per *paid* plan. Free ($0) gets
        // no Stripe object — a $0 plan never starts a subscription.
        foreach ($plans as $planKey => $plan) {
            $amount = (int) ($plan['price_cents'] ?? 0);
            if ($amount <= 0) {
                continue;
            }

            $label = (string) ($plan['label'] ?? ucfirst((string) $planKey));
            $ceiling = $plan['max_servers'] ?? null;
            $ceilingText = $ceiling === null
                ? 'unlimited servers'
                : 'up to '.$ceiling.' '.($ceiling === 1 ? 'server' : 'servers');

            $product = $this->upsertProduct(
                name: 'dply '.$label,
                description: 'dply '.$label.' plan — flat monthly fee for '.$ceilingText.'. Metered by how many BYO servers dply manages, not their size; you pay your own provider for the hardware. Every feature is included; sites and team members are unlimited.',
                role: self::ROLE_PLAN_PRODUCT_PREFIX.$planKey,
            );
            $result[self::ROLE_PLAN_PRODUCT_PREFIX.$planKey] = $product->id;

            $monthlyRole = self::ROLE_PLAN_PREFIX.$planKey;
            $result[$monthlyRole] = $this->upsertRecurringPrice(
                productId: $product->id,
                amount: $amount,
                interval: 'month',
                nickname: $label.' — Monthly',
                role: $monthlyRole,
            )->id;

            $yearlyRole = $monthlyRole.self::ROLE_PLAN_YEARLY_SUFFIX;
            $result[$yearlyRole] = $this->upsertRecurringPrice(
                productId: $product->id,
                amount: $this->annualAmount($amount, $annualPct),
                interval: 'year',
                nickname: $label.' — Yearly',
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

        // Metered managed-serverless usage — invocations beyond the included
        // allowance plus marked-up managed DB/cache resources, billed per cent
        // (quantity = cents) on top of the flat per-function fee. Only accrues
        // for dply-managed functions (dply pays the provider).
        $serverlessUsageUnitCents = (int) ($standardConfig['serverless_usage_unit_cents'] ?? 1);
        if ($serverlessUsageUnitCents > 0) {
            $serverlessUsageProduct = $this->upsertProduct(
                name: 'dply serverless usage',
                description: 'Metered usage for dply-managed serverless functions — invocations beyond the included monthly allowance plus managed databases and caches. Billed monthly in pass-through-plus-margin units on top of the flat per-function fee.',
                role: self::ROLE_SERVERLESS_USAGE_PRODUCT,
            );
            $result[self::ROLE_SERVERLESS_USAGE_PRODUCT] = $serverlessUsageProduct->id;

            $result[self::ROLE_SERVERLESS_USAGE_MONTHLY] = $this->upsertRecurringPrice(
                productId: $serverlessUsageProduct->id,
                amount: $serverlessUsageUnitCents,
                interval: 'month',
                nickname: 'Serverless usage — Monthly (per cent)',
                role: self::ROLE_SERVERLESS_USAGE_MONTHLY,
            )->id;
        }

        // Metered dply-managed server — all-in cost-plus (Hetzner provider price
        // × markup) billed per cent (quantity = cents), monthly. Replaces the
        // per-server tier fee for VMs dply runs on its own infrastructure.
        $managedServerUnitCents = (int) ($standardConfig['managed_server_usage_unit_cents'] ?? 1);
        if ($managedServerUnitCents > 0) {
            $managedServerProduct = $this->upsertProduct(
                name: 'dply managed server',
                description: 'All-in monthly fee for a dply-managed server — dply provisions and pays for the VM on its own infrastructure and bills the provider cost plus margin. Billed monthly in per-cent units; replaces the per-server plan fee.',
                role: self::ROLE_MANAGED_SERVER_PRODUCT,
            );
            $result[self::ROLE_MANAGED_SERVER_PRODUCT] = $managedServerProduct->id;

            $result[self::ROLE_MANAGED_SERVER_MONTHLY] = $this->upsertRecurringPrice(
                productId: $managedServerProduct->id,
                amount: $managedServerUnitCents,
                interval: 'month',
                nickname: 'Managed server — Monthly (per cent)',
                role: self::ROLE_MANAGED_SERVER_MONTHLY,
            )->id;
        }

        $cloudCents = (int) ($standardConfig['cloud_cents'] ?? 500);
        if ($cloudCents > 0) {
            $cloudProduct = $this->upsertProduct(
                name: 'dply Cloud app',
                description: 'Per-app fee for dply Cloud — long-running container apps on dply-owned infrastructure. Covers builds, deploys, scaling, and console management. Billed per live app, not per VM.',
                role: self::ROLE_CLOUD_PRODUCT,
            );
            $result[self::ROLE_CLOUD_PRODUCT] = $cloudProduct->id;

            $result[self::ROLE_CLOUD_MONTHLY] = $this->upsertRecurringPrice(
                productId: $cloudProduct->id,
                amount: $cloudCents,
                interval: 'month',
                nickname: 'Cloud app — Monthly',
                role: self::ROLE_CLOUD_MONTHLY,
            )->id;

            $result[self::ROLE_CLOUD_YEARLY] = $this->upsertRecurringPrice(
                productId: $cloudProduct->id,
                amount: $this->annualAmount($cloudCents, $annualPct),
                interval: 'year',
                nickname: 'Cloud app — Yearly',
                role: self::ROLE_CLOUD_YEARLY,
            )->id;
        }

        // Metered Cloud resources — the marked-up DigitalOcean container /
        // worker / database / bucket cost backing each Cloud app, billed per
        // cent (quantity = cents) on top of the flat per-app platform fee.
        $cloudUsageUnitCents = (int) ($standardConfig['cloud_usage_unit_cents'] ?? 1);
        if ($cloudUsageUnitCents > 0) {
            $cloudUsageProduct = $this->upsertProduct(
                name: 'dply Cloud resources',
                description: 'Metered infrastructure for dply Cloud apps — container compute, background workers, managed databases, and object storage. Billed monthly in pass-through-plus-margin units on top of the flat per-app fee.',
                role: self::ROLE_CLOUD_USAGE_PRODUCT,
            );
            $result[self::ROLE_CLOUD_USAGE_PRODUCT] = $cloudUsageProduct->id;

            $result[self::ROLE_CLOUD_USAGE_MONTHLY] = $this->upsertRecurringPrice(
                productId: $cloudUsageProduct->id,
                amount: $cloudUsageUnitCents,
                interval: 'month',
                nickname: 'Cloud resources — Monthly (per cent)',
                role: self::ROLE_CLOUD_USAGE_MONTHLY,
            )->id;
        }

        $edgeCents = (int) ($standardConfig['edge_cents'] ?? 200);
        if ($edgeCents > 0) {
            $edgeProduct = $this->upsertProduct(
                name: 'dply Edge site',
                description: 'Per-site fee for dply Edge — static and SSG sites on dply-owned CDN infrastructure. Covers builds, deploys, previews, and global delivery. Billed per live production site.',
                role: self::ROLE_EDGE_PRODUCT,
            );
            $result[self::ROLE_EDGE_PRODUCT] = $edgeProduct->id;

            $result[self::ROLE_EDGE_MONTHLY] = $this->upsertRecurringPrice(
                productId: $edgeProduct->id,
                amount: $edgeCents,
                interval: 'month',
                nickname: 'Edge site — Monthly',
                role: self::ROLE_EDGE_MONTHLY,
            )->id;

            $result[self::ROLE_EDGE_YEARLY] = $this->upsertRecurringPrice(
                productId: $edgeProduct->id,
                amount: $this->annualAmount($edgeCents, $annualPct),
                interval: 'year',
                nickname: 'Edge site — Yearly',
                role: self::ROLE_EDGE_YEARLY,
            )->id;
        }

        $edgeUsageUnitCents = (int) ($standardConfig['edge_usage_unit_cents'] ?? 1);
        if ($edgeUsageUnitCents > 0) {
            $edgeUsageProduct = $this->upsertProduct(
                name: 'dply Edge delivery usage',
                description: 'Metered Edge CDN delivery — HTTP requests, bandwidth, and R2 storage beyond per-site included allowances. Billed monthly in pass-through units.',
                role: self::ROLE_EDGE_USAGE_PRODUCT,
            );
            $result[self::ROLE_EDGE_USAGE_PRODUCT] = $edgeUsageProduct->id;

            $result[self::ROLE_EDGE_USAGE_MONTHLY] = $this->upsertRecurringPrice(
                productId: $edgeUsageProduct->id,
                amount: $edgeUsageUnitCents,
                interval: 'month',
                nickname: 'Edge delivery usage — Monthly (per cent)',
                role: self::ROLE_EDGE_USAGE_MONTHLY,
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
        // Managed products + edge usage map to fixed env var names.
        $static = [
            self::ROLE_SERVERLESS_MONTHLY => 'STRIPE_PRICE_STANDARD_SERVERLESS',
            self::ROLE_SERVERLESS_YEARLY => 'STRIPE_PRICE_STANDARD_SERVERLESS_YEARLY',
            self::ROLE_SERVERLESS_USAGE_MONTHLY => 'STRIPE_PRICE_STANDARD_SERVERLESS_USAGE',
            self::ROLE_MANAGED_SERVER_MONTHLY => 'STRIPE_PRICE_STANDARD_MANAGED_SERVER',
            self::ROLE_CLOUD_MONTHLY => 'STRIPE_PRICE_STANDARD_CLOUD',
            self::ROLE_CLOUD_YEARLY => 'STRIPE_PRICE_STANDARD_CLOUD_YEARLY',
            self::ROLE_CLOUD_USAGE_MONTHLY => 'STRIPE_PRICE_STANDARD_CLOUD_USAGE',
            self::ROLE_EDGE_MONTHLY => 'STRIPE_PRICE_STANDARD_EDGE',
            self::ROLE_EDGE_YEARLY => 'STRIPE_PRICE_STANDARD_EDGE_YEARLY',
            self::ROLE_EDGE_USAGE_MONTHLY => 'STRIPE_PRICE_STANDARD_EDGE_USAGE',
        ];

        $lines = [];
        foreach ($result as $role => $id) {
            $role = (string) $role;

            // Plan price roles → STRIPE_PRICE_STANDARD_{KEY}[_YEARLY]. Skip the
            // plan *product* roles — operators don't need product IDs at runtime.
            if (str_starts_with($role, self::ROLE_PLAN_PRODUCT_PREFIX)) {
                continue;
            }
            if (str_starts_with($role, self::ROLE_PLAN_PREFIX)) {
                $key = substr($role, strlen(self::ROLE_PLAN_PREFIX));
                $lines[] = 'STRIPE_PRICE_STANDARD_'.strtoupper($key).'='.$id;

                continue;
            }

            if (isset($static[$role])) {
                $lines[] = $static[$role].'='.$id;
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
    private function upsertProduct(string $name, string $description, string $role): Product
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
    ): Price {
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
