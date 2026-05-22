<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Billing\StripeBillingProvisioner;
use Illuminate\Console\Command;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * One-shot provisioning command that creates the Stripe products, prices, and
 * coupon backing the Standard plan. Idempotent — re-running after a partial
 * failure picks up where it left off, and re-running after success is a no-op
 * (looks up existing objects by `metadata.dply_role` before creating).
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

    protected $description = 'Create the Stripe products, prices, and coupon for the Standard plan (idempotent).';

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
        $base = (int) ($standard['base_cents'] ?? 0);
        $annualPct = (int) ($standard['annual_discount_pct'] ?? 0);
        $tiers = (array) ($standard['tiers'] ?? []);
        $yearlyOf = fn (int $cents): int => (int) round($cents * 12 * (100 - $annualPct) / 100);

        $this->info('Dry-run — these objects would be created or matched in Stripe:');
        $this->newLine();
        $this->line('  Product: dply Standard');
        $this->line(sprintf('    Base monthly: $%s/mo', number_format($base / 100, 2)));
        $this->line(sprintf('    Base yearly:  $%s/yr (%s%% off)', number_format($yearlyOf($base) / 100, 2), $annualPct));
        foreach (['xs', 's', 'm', 'l', 'xl'] as $tier) {
            $amount = (int) ($tiers[$tier] ?? 0);
            $this->line(sprintf(
                '    Tier %-3s     $%s/mo   $%s/yr',
                strtoupper($tier),
                number_format($amount / 100, 2),
                number_format($yearlyOf($amount) / 100, 2),
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
        $this->line('  Product: dply Enterprise (no prices — sales-led)');
        $this->newLine();
        $this->info('Re-run without --dry-run to actually create these in Stripe.');

        return self::SUCCESS;
    }
}
