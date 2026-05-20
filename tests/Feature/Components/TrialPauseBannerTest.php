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
    }

    public function test_subscribed_org_shows_no_banner(): void
    {
        $org = new class extends Organization
        {
            public function onStandardSubscription(): bool
            {
                return true;
            }
        };
        $org->forceFill(Organization::factory()->create()->getAttributes());
        $org->exists = true;

        $html = Blade::render('<x-trial-pause-banner :organization="$organization" />', [
            'organization' => $org,
        ]);

        $this->assertStringNotContainsString('trial', strtolower($html));
        $this->assertStringNotContainsString('paused', strtolower($html));
    }
}
