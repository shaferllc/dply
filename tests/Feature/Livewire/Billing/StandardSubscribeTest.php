<?php

namespace Tests\Feature\Livewire\Billing;

use App\Livewire\Billing\Show as BillingShow;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;
use Tests\TestCase;

class StandardSubscribeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->org = Organization::factory()->create();
        $this->org->users()->attach($this->admin->id, ['role' => 'admin']);

        Config::set('subscription.standard.stripe.base_monthly', 'price_test_base_monthly');
        Config::set('subscription.standard.stripe.base_yearly', 'price_test_base_yearly');
    }

    public function test_on_dply_trial_property_reflects_trial_window(): void
    {
        $this->org->update(['trial_ends_at' => now()->addDays(7)]);

        Livewire::actingAs($this->admin)
            ->test(BillingShow::class, ['organization' => $this->org])
            ->assertSet('onDplyTrial', true);
    }

    public function test_trial_days_left_is_zero_after_trial_expires(): void
    {
        $org = Organization::factory()->create(['trial_ends_at' => now()->subDay()]);
        $org->users()->attach($this->admin->id, ['role' => 'admin']);

        Livewire::actingAs($this->admin)
            ->test(BillingShow::class, ['organization' => $org])
            ->assertSet('onDplyTrial', false)
            ->assertSet('dplyTrialDaysLeft', 0);
    }

    public function test_subscribe_rejects_invalid_intervals(): void
    {
        Livewire::actingAs($this->admin)
            ->test(BillingShow::class, ['organization' => $this->org])
            ->call('subscribeStandard', 'weekly')
            ->assertHasErrors('plan');
    }

    public function test_subscribe_rejects_when_already_subscribed(): void
    {
        Subscription::factory()
            ->withPrice('price_test_base_monthly')
            ->active()
            ->create(['organization_id' => $this->org->id]);

        Livewire::actingAs($this->admin)
            ->test(BillingShow::class, ['organization' => $this->org])
            ->call('subscribeStandard', 'month')
            ->assertHasErrors('billing');
    }

    public function test_subscribe_fails_gracefully_when_pricing_not_configured(): void
    {
        Config::set('subscription.standard.stripe.base_monthly', '');
        Config::set('subscription.standard.stripe.base_yearly', '');

        Livewire::actingAs($this->admin)
            ->test(BillingShow::class, ['organization' => $this->org])
            ->call('subscribeStandard', 'month')
            ->assertHasErrors('billing');
    }

    public function test_non_admin_cannot_subscribe(): void
    {
        $member = User::factory()->create();
        $this->org->users()->attach($member->id, ['role' => 'member']);

        Livewire::actingAs($member)
            ->test(BillingShow::class, ['organization' => $this->org])
            ->assertForbidden();
    }
}
