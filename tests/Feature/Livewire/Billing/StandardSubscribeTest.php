<?php

namespace Tests\Feature\Livewire\Billing\StandardSubscribeTest;

use App\Livewire\Billing\Show as BillingShow;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->admin = User::factory()->create();
    $this->org = Organization::factory()->create();
    $this->org->users()->attach($this->admin->id, ['role' => 'admin']);

    Config::set('subscription.standard.stripe.base_monthly', 'price_test_base_monthly');
    Config::set('subscription.standard.stripe.base_yearly', 'price_test_base_yearly');
});

test('on dply trial property reflects trial window', function () {
    $this->org->update(['trial_ends_at' => now()->addDays(7)]);

    Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $this->org])
        ->assertSet('onDplyTrial', true);
});

test('trial days left is zero after trial expires', function () {
    $org = Organization::factory()->create(['trial_ends_at' => now()->subDay()]);
    $org->users()->attach($this->admin->id, ['role' => 'admin']);

    Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $org])
        ->assertSet('onDplyTrial', false)
        ->assertSet('dplyTrialDaysLeft', 0);
});

test('subscribe rejects invalid intervals', function () {
    Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $this->org])
        ->call('subscribeStandard', 'weekly')
        ->assertHasErrors('plan');
});

test('subscribe rejects when already subscribed', function () {
    Subscription::factory()
        ->withPrice('price_test_base_monthly')
        ->active()
        ->create(['organization_id' => $this->org->id]);

    Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $this->org])
        ->call('subscribeStandard', 'month')
        ->assertHasErrors('billing');
});

test('subscribe fails gracefully when pricing not configured', function () {
    Config::set('subscription.standard.stripe.base_monthly', '');
    Config::set('subscription.standard.stripe.base_yearly', '');

    Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $this->org])
        ->call('subscribeStandard', 'month')
        ->assertHasErrors('billing');
});

test('non admin cannot subscribe', function () {
    $member = User::factory()->create();
    $this->org->users()->attach($member->id, ['role' => 'member']);

    Livewire::actingAs($member)
        ->test(BillingShow::class, ['organization' => $this->org])
        ->assertForbidden();
});

test('switch interval rejects when no subscription', function () {
    Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $this->org])
        ->call('switchInterval')
        ->assertRedirect();

    expect(session('billing_error'))->not->toBeNull();
});

test('switch interval rejects when target prices unconfigured', function () {
    Subscription::factory()
        ->withPrice('price_test_base_monthly')
        ->active()
        ->create(['organization_id' => $this->org->id]);

    // Current interval resolves to monthly → target is yearly → unconfigure it.
    Config::set('subscription.standard.stripe.base_yearly', '');

    Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $this->org])
        ->call('switchInterval')
        ->assertRedirect();

    expect(session('billing_error'))->not->toBeNull();
});

test('cancel rejects when no active subscription', function () {
    Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $this->org])
        ->call('cancelSubscription')
        ->assertRedirect();

    expect(session('billing_error'))->not->toBeNull();
});

test('cancel rejects when already canceled', function () {
    Subscription::factory()
        ->withPrice('price_test_base_monthly')
        ->create([
            'organization_id' => $this->org->id,
            'stripe_status' => 'canceled',
            'ends_at' => now()->addDays(10), // grace period
        ]);

    Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $this->org])
        ->assertSet('onGracePeriod', true)
        ->call('cancelSubscription')
        ->assertRedirect();

    expect(session('billing_error'))->not->toBeNull();
});

test('resume rejects when not in grace period', function () {
    Subscription::factory()
        ->withPrice('price_test_base_monthly')
        ->active()
        ->create(['organization_id' => $this->org->id]);

    Livewire::actingAs($this->admin)
        ->test(BillingShow::class, ['organization' => $this->org])
        ->call('resumeSubscription')
        ->assertRedirect();

    expect(session('billing_error'))->not->toBeNull();
});
