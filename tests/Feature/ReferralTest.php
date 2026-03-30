<?php

namespace Tests\Feature;

use App\Livewire\Auth\Register;
use App\Models\Organization;
use App\Models\ReferralReward;
use App\Models\User;
use App\Services\Referrals\ReferralConversionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Events\WebhookReceived;
use Livewire\Livewire;
use Tests\TestCase;

class ReferralTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_referrer_query_sets_session(): void
    {
        $referrer = User::factory()->create();

        $this->get(route('register', ['referrer' => $referrer->referral_code], false))
            ->assertOk();

        $this->assertSame($referrer->referral_code, session('referral_code'));
    }

    public function test_invalid_referrer_query_does_not_set_session(): void
    {
        $this->get(route('register', ['referrer' => 'not-a-real-code'], false))
            ->assertOk();

        $this->assertNull(session('referral_code'));
    }

    public function test_registration_assigns_referrer_from_session(): void
    {
        $referrer = User::factory()->create();

        $this->withSession(['referral_code' => $referrer->referral_code]);

        Livewire::test(Register::class)
            ->set('form.name', 'Referred User')
            ->set('form.email', 'referred@example.com')
            ->set('form.password', 'password')
            ->set('form.password_confirmation', 'password')
            ->call('submit');

        $referred = User::query()->where('email', 'referred@example.com')->first();
        $this->assertNotNull($referred);
        $this->assertSame($referrer->id, $referred->referred_by_user_id);
    }

    public function test_invoice_webhook_marks_conversion_and_records_reward(): void
    {
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
        $this->assertNotNull($referred->referral_converted_at);

        $this->assertDatabaseHas('referral_rewards', [
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $referred->id,
        ]);

        $this->assertSame(1, ReferralReward::query()->count());
    }

    public function test_conversion_service_skips_without_pro_price_match(): void
    {
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
        $this->assertNull($referred->referral_converted_at);
        $this->assertSame(0, ReferralReward::query()->count());
    }
}
