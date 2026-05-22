<?php


namespace Tests\Feature\Components\TrialPauseBannerTest;
use App\Models\Organization;
use Illuminate\Support\Facades\Blade;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function render(Organization $org): string
{
    return render('<x-trial-pause-banner :organization="$organization" />', [
        'organization' => $org->fresh(),
    ]);
}

test('active trial shows countdown with subscribe cta', function () {
    $org = Organization::factory()->create(['trial_ends_at' => now()->addDays(9)]);

    $html = render($org);

    $this->assertStringContainsString('days left in your trial', $html);
    $this->assertStringContainsString('Subscribe', $html);
});

test('active trial is calm when more than three days left', function () {
    $org = Organization::factory()->create(['trial_ends_at' => now()->addDays(9)]);

    $html = render($org);

    // Calm styling — brand-gold border, not amber.
    $this->assertStringContainsString('border-brand-gold/30', $html);
    $this->assertStringNotContainsString('border-amber-300', $html);
});

test('active trial escalates to amber in final three days', function () {
    $org = Organization::factory()->create(['trial_ends_at' => now()->addDays(2)]);

    $html = render($org);

    $this->assertStringContainsString('border-amber-300', $html);
});

test('trial ending tomorrow uses singular copy', function () {
    $org = Organization::factory()->create(['trial_ends_at' => now()->addHours(20)]);

    $html = render($org);

    $this->assertStringContainsString('ends tomorrow', $html);
});

test('expired soft still renders pause banner', function () {
    $org = Organization::factory()->create(['trial_ends_at' => now()->subDays(5)]);

    $html = render($org);

    $this->assertStringContainsString('Deploys are paused', $html);
    $this->assertStringContainsString('your trial has ended', $html);
});

test('expired soft after cancellation says subscription ended', function () {
    $org = Organization::factory()->create(['trial_ends_at' => null]);
    \App\Models\Subscription::factory()
        ->withPrice('price_x')
        ->create([
            'organization_id' => $org->id,
            'stripe_status' => 'canceled',
            'ends_at' => now()->subDays(5),
        ]);

    $html = render($org);

    $this->assertStringContainsString('Deploys are paused', $html);
    $this->assertStringContainsString('your subscription ended', $html);
    $this->assertStringContainsString('Resume', $html);
});

test('subscribed org shows no banner', function () {
    \Illuminate\Support\Facades\Config::set('subscription.standard.stripe.base_monthly', 'price_sub_base');
    $org = Organization::factory()->create(['trial_ends_at' => null]);
    \App\Models\Subscription::factory()
        ->withPrice('price_sub_base')
        ->active()
        ->create(['organization_id' => $org->id]);

    $html = render($org);

    $this->assertStringNotContainsString('trial', strtolower($html));
    $this->assertStringNotContainsString('paused', strtolower($html));
    $this->assertStringNotContainsString('subscription ends', strtolower($html));
});

test('grace period shows resume banner with end date', function () {
    \Illuminate\Support\Facades\Config::set('subscription.standard.stripe.base_monthly', 'price_sub_base');
    $org = Organization::factory()->create(['trial_ends_at' => null]);
    \App\Models\Subscription::factory()
        ->withPrice('price_sub_base')
        ->create([
            'organization_id' => $org->id,
            'stripe_status' => 'active',
            'ends_at' => now()->addDays(12), // canceled, still in grace
        ]);

    $html = render($org);

    $this->assertStringContainsString('Your subscription ends', $html);
    $this->assertStringContainsString('Resume subscription', $html);
    $this->assertStringContainsString('full access until then', $html);
});