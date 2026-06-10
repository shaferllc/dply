<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Billing\StripeBillingProvisioner;
use Illuminate\Console\Command;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * One-shot provisioning command that creates the Stripe products and prices
 * backing the flat plan model (Starter / Pro / Business + managed products).
 * Idempotent — re-running after a partial failure picks up where it left off,
 * and re-running after success is a no-op (looks up existing objects by
 * `metadata.dply_role` before creating).
 *
 * After it succeeds, paste the printed env vars into your .env (or your
 * secrets manager) and restart the app so config('subscription.standard.*')
 * picks up the new IDs.
 *
 * Examples:
 *   php artisan dply:billing:provision-stripe --dry-run
 *   php artisan dply:billing:provision-stripe
 */
class ProvisionStripeBillingCommand extends Command
{
    protected $signature = 'dply:billing:provision-stripe
                            {--dry-run : Inspect what would be created without calling Stripe}';

    protected $description = 'Create the Stripe products and prices for the flat plan model (idempotent).';

    public function handle(): int
    {
        if (! is_string($secret = config('cashier.secret')) || $secret === '') {
            $this->error('cashier.secret is not configured. Set STRIPE_SECRET in .env first.');

            return self::FAILURE;
        }

        if ((bool) $this->option('dry-run')) {
            return $this->dryRun();
        }

        try {
            $stripe = Organization::stripe();
        } catch (\Throwable $e) {
            $this->error('Could not initialise Stripe client: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Provisioning Stripe billing objects… (idempotent, safe to re-run)');

        try {
            $provisioner = new StripeBillingProvisioner($stripe instanceof StripeClient ? $stripe : new StripeClient($secret));
            $result = $provisioner->provision();
        } catch (ApiErrorException $e) {
            $this->error('Stripe API error: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Created or reused the following:');
        foreach ($result as $role => $id) {
            $this->line(sprintf('  %-30s %s', $role, $id));
        }

        $this->newLine();
        $this->info('Paste these env vars into your .env (then restart the app):');
        $this->newLine();
        $this->line(StripeBillingProvisioner::formatEnv($result));
        $this->newLine();

        return self::SUCCESS;
    }

    private function dryRun(): int
    {
        $standard = (array) config('subscription.standard', []);
        $annualPct = (int) ($standard['annual_discount_pct'] ?? 0);
        $plans = (array) ($standard['plans'] ?? []);
        $yearlyOf = fn (int $cents): int => (int) round($cents * 12 * (100 - $annualPct) / 100);

        $this->info('Dry-run — these objects would be created or matched in Stripe:');
        $this->newLine();
        $this->line('  Plans (metered by BYO server count):');
        foreach ($plans as $key => $plan) {
            $amount = (int) ($plan['price_cents'] ?? 0);
            $label = (string) ($plan['label'] ?? ucfirst((string) $key));
            if ($amount <= 0) {
                $this->line(sprintf('    %-9s free, no Stripe object', $label));

                continue;
            }
            $this->line(sprintf(
                '    %-9s $%s/mo   $%s/yr (%s%% off)',
                $label,
                number_format($amount / 100, 2),
                number_format($yearlyOf($amount) / 100, 2),
                $annualPct,
            ));
        }
        $serverless = (int) ($standard['serverless_cents'] ?? 0);
        if ($serverless > 0) {
            $this->line('  Product: dply serverless function');
            $this->line(sprintf(
                '    Per function $%s/mo   $%s/yr',
                number_format($serverless / 100, 2),
                number_format($yearlyOf($serverless) / 100, 2),
            ));
        }
        $cloud = (int) ($standard['cloud_cents'] ?? 0);
        if ($cloud > 0) {
            $this->line('  Product: dply Cloud app');
            $this->line(sprintf(
                '    Per app $%s/mo   $%s/yr',
                number_format($cloud / 100, 2),
                number_format($yearlyOf($cloud) / 100, 2),
            ));
        }
        $edge = (int) ($standard['edge_cents'] ?? 0);
        if ($edge > 0) {
            $this->line('  Product: dply Edge site');
            $this->line(sprintf(
                '    Per site $%s/mo   $%s/yr',
                number_format($edge / 100, 2),
                number_format($yearlyOf($edge) / 100, 2),
            ));
        }
        $edgeUsageUnit = (int) ($standard['edge_usage_unit_cents'] ?? 1);
        if ($edgeUsageUnit > 0) {
            $this->line('  Product: dply Edge delivery usage');
            $this->line(sprintf(
                '    Metered $%s/unit (monthly, quantity = cents)',
                number_format($edgeUsageUnit / 100, 2),
            ));
        }
        $realtimeTiers = (array) config('realtime.tiers', []);
        if ($realtimeTiers !== []) {
            $this->line('  Product: dply Realtime app (per connection-tier)');
            foreach ($realtimeTiers as $slug => $tier) {
                $tierCents = (int) ($tier['price_cents'] ?? 0);
                if ($tierCents <= 0) {
                    continue;
                }
                $this->line(sprintf(
                    '    %s ($%s/mo   $%s/yr)',
                    (string) ($tier['label'] ?? ucfirst((string) $slug)),
                    number_format($tierCents / 100, 2),
                    number_format($yearlyOf($tierCents) / 100, 2),
                ));
            }
        }
        $this->line('  Product: dply Enterprise (no prices — sales-led)');
        $this->newLine();
        $this->info('Re-run without --dry-run to actually create these in Stripe.');

        return self::SUCCESS;
    }
}
