<?php

namespace Tests\Feature\Components;

use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class TrialPauseBannerTest extends TestCase
{
    use RefreshDatabase;

    private function render(Organization $org): string
    {
        return Blade::render('<x-trial-pause-banner :organization="$organization" />', [
            'organization' => $org->fresh(),
        ]);
    }

    public function test_active_trial_shows_countdown_with_subscribe_cta(): void
    {
        $org = Organization::factory()->create(['trial_ends_at' => now()->addDays(9)]);

        $html = $this->render($org);

        $this->assertStringContainsString('days left in your trial', $html);
        $this->assertStringContainsString('Subscribe', $html);
    }

    public function test_active_trial_is_calm_when_more_than_three_days_left(): void
    {
        $org = Organization::factory()->create(['trial_ends_at' => now()->addDays(9)]);

        $html = $this->render($org);

        // Calm styling — brand-gold border, not amber.
        $this->assertStringContainsString('border-brand-gold/30', $html);
        $this->assertStringNotContainsString('border-amber-300', $html);
    }

    public function test_active_trial_escalates_to_amber_in_final_three_days(): void
    {
        $org = Organization::factory()->create(['trial_ends_at' => now()->addDays(2)]);

        $html = $this->render($org);

        $this->assertStringContainsString('border-amber-300', $html);
    }

    public function test_trial_ending_tomorrow_uses_singular_copy(): void
    {
        $org = Organization::factory()->create(['trial_ends_at' => now()->addHours(20)]);

        $html = $this->render($org);

        $this->assertStringContainsString('ends tomorrow', $html);
    }

    public function test_expired_soft_still_renders_pause_banner(): void
    {
        $org = Organization::factory()->create(['trial_ends_at' => now()->subDays(5)]);

        $html = $this->render($org);

        $this->assertStringContainsString('Deploys are paused', $html);
        $this->assertStringContainsString('your trial has ended', $html);
    }

    public function test_expired_soft_after_cancellation_says_subscription_ended(): void
    {
        $org = Organization::factory()->create(['trial_ends_at' => null]);
        \App\Models\Subscription::factory()
            ->withPrice('price_x')
            ->create([
                'organization_id' => $org->id,
                'stripe_status' => 'canceled',
                'ends_at' => now()->subDays(5),
            ]);

        $html = $this->render($org);

        $this->assertStringContainsString('Deploys are paused', $html);
        $this->assertStringContainsString('your subscription ended', $html);
        $this->assertStringContainsString('Resume', $html);
    }

    public function test_subscribed_org_shows_no_banner(): void
    {
        \Illuminate\Support\Facades\Config::set('subscription.standard.stripe.base_monthly', 'price_sub_base');
        $org = Organization::factory()->create(['trial_ends_at' => null]);
        \App\Models\Subscription::factory()
            ->withPrice('price_sub_base')
            ->active()
            ->create(['organization_id' => $org->id]);

        $html = $this->render($org);

        $this->assertStringNotContainsString('trial', strtolower($html));
        $this->assertStringNotContainsString('paused', strtolower($html));
        $this->assertStringNotContainsString('subscription ends', strtolower($html));
    }

    public function test_grace_period_shows_resume_banner_with_end_date(): void
    {
        \Illuminate\Support\Facades\Config::set('subscription.standard.stripe.base_monthly', 'price_sub_base');
        $org = Organization::factory()->create(['trial_ends_at' => null]);
        \App\Models\Subscription::factory()
            ->withPrice('price_sub_base')
            ->create([
                'organization_id' => $org->id,
                'stripe_status' => 'active',
                'ends_at' => now()->addDays(12), // canceled, still in grace
            ]);

        $html = $this->render($org);

        $this->assertStringContainsString('Your subscription ends', $html);
        $this->assertStringContainsString('Resume subscription', $html);
        $this->assertStringContainsString('full access until then', $html);
    }
}
