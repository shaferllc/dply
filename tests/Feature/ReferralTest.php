<?php

namespace Tests\Feature\ReferralTest;

use App\Http\Middleware\RedirectGuestsToComingSoon;
use App\Livewire\Auth\Register;
use App\Models\Organization;
use App\Models\ReferralReward;
use App\Models\User;
use App\Services\Referrals\ReferralConversionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Events\WebhookReceived;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('valid referrer query sets session', function () {
    $referrer = User::factory()->create();

    $this->withoutMiddleware([RedirectGuestsToComingSoon::class])
        ->get(route('register', ['referrer' => $referrer->referral_code], false))
        ->assertOk();

    expect(session('referral_code'))->toBe($referrer->referral_code);
});

test('invalid referrer query does not set session', function () {
    $this->withoutMiddleware([RedirectGuestsToComingSoon::class])
        ->get(route('register', ['referrer' => 'not-a-real-code'], false))
        ->assertOk();

    expect(session('referral_code'))->toBeNull();
});

test('registration assigns referrer from session', function () {
    $referrer = User::factory()->create();

    $this->withSession(['referral_code' => $referrer->referral_code]);

    Livewire::test(Register::class)
        ->set('form.name', 'Referred User')
        ->set('form.email', 'referred@example.com')
        ->set('form.password', 'password')
        ->set('form.password_confirmation', 'password')
        ->call('submit');

    $referred = User::query()->where('email', 'referred@example.com')->first();
    expect($referred)->not->toBeNull();
    expect($referred->referred_by_user_id)->toBe($referrer->id);
});

test('invoice webhook marks conversion and records reward', function () {
    config([
        'subscription.plans.pro_monthly.price_id' => 'price_test_pro',
        'referral.bonus_credit_cents' => 0,
    ]);

    $referrer = User::factory()->create();
    $referred = User::factory()->create([
        'referred_by_user_id' => $referrer->id,
        'referral_converted_at' => null,
    ]);

    $org = Organization::factory()->create([
        'stripe_id' => 'cus_referral_test',
    ]);
    $org->users()->attach($referred->id, ['role' => 'owner']);

    $payload = [
        'type' => 'invoice.payment_succeeded',
        'data' => [
            'object' => [
                'id' => 'in_test_1',
                'customer' => 'cus_referral_test',
                'amount_paid' => 2900,
                'subscription' => 'sub_test_1',
                'lines' => [
                    'data' => [
                        [
                            'type' => 'subscription',
                            'amount' => 2900,
                            'price' => [
                                'id' => 'price_test_pro',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];

    event(new WebhookReceived($payload));

    $referred->refresh();
    expect($referred->referral_converted_at)->not->toBeNull();

    $this->assertDatabaseHas('referral_rewards', [
        'referrer_user_id' => $referrer->id,
        'referred_user_id' => $referred->id,
    ]);

    expect(ReferralReward::query()->count())->toBe(1);
});

test('conversion service skips without pro price match', function () {
    config([
        'subscription.plans.pro_monthly.price_id' => 'price_real_pro',
        'subscription.plans.pro_yearly.price_id' => '',
    ]);

    $referrer = User::factory()->create();
    $referred = User::factory()->create([
        'referred_by_user_id' => $referrer->id,
        'referral_converted_at' => null,
    ]);

    $org = Organization::factory()->create(['stripe_id' => 'cus_x']);
    $org->users()->attach($referred->id, ['role' => 'owner']);

    $payload = [
        'type' => 'invoice.payment_succeeded',
        'data' => [
            'object' => [
                'customer' => 'cus_x',
                'amount_paid' => 100,
                'subscription' => 'sub_x',
                'lines' => [
                    'data' => [
                        [
                            'type' => 'subscription',
                            'price' => ['id' => 'price_other'],
                        ],
                    ],
                ],
            ],
        ],
    ];

    app(ReferralConversionService::class)->handleInvoicePaymentSucceeded($payload);

    $referred->refresh();
    expect($referred->referral_converted_at)->toBeNull();
    expect(ReferralReward::query()->count())->toBe(0);
});
