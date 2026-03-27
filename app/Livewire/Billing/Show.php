<?php

namespace App\Livewire\Billing;

use App\Models\Organization;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Laravel\Cashier\Invoice;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

#[Layout('layouts.app')]
class Show extends Component
{
    public Organization $organization;

    public function mount(Organization $organization): void
    {
        $this->authorize('update', $organization);
        $this->organization = $organization;
    }

    public function getSubscriptionProperty()
    {
        return $this->organization->subscription('default');
    }

    public function getStatusProperty(): ?string
    {
        $sub = $this->getSubscriptionProperty();

        return $sub ? $sub->stripe_status : null;
    }

    public function getPlanNameProperty(): ?string
    {
        $sub = $this->getSubscriptionProperty();
        if (! $sub) {
            return null;
        }
        $priceId = $sub->stripe_price ?? $sub->items->first()?->stripe_price;
        if (! $priceId) {
            return null;
        }
        $plans = config('subscription.plans', []);
        foreach ($plans as $plan) {
            if (($plan['price_id'] ?? '') === $priceId) {
                return $plan['name'] ?? $priceId;
            }
        }

        return $priceId;
    }

    public function getPaymentSummaryProperty(): string
    {
        $org = $this->organization;
        if ($org->pm_last_four) {
            return '•••• '.$org->pm_last_four;
        }
        $paymentMethod = $org->defaultPaymentMethod();
        if ($paymentMethod && method_exists($paymentMethod, 'asStripePaymentMethod')) {
            $pm = $paymentMethod->asStripePaymentMethod();
            if (isset($pm->card->last4)) {
                return '•••• '.$pm->card->last4;
            }
        }

        return 'No payment method';
    }

    public function getPlansProperty(): Collection
    {
        return collect(config('subscription.plans', []))->filter(fn ($p) => ! empty($p['price_id']));
    }

    public function getCanManageBillingProperty(): bool
    {
        return $this->organization->hasStripeId();
    }

    /**
     * @return Collection<int, Invoice>
     */
    public function getInvoicesProperty(): Collection
    {
        if (! $this->organization->hasStripeId()) {
            return collect();
        }

        try {
            return $this->organization->invoices(false, ['limit' => 12]);
        } catch (Throwable) {
            return collect();
        }
    }

    public function checkout(string $plan): mixed
    {
        $this->authorize('update', $this->organization);

        $plans = config('subscription.plans', []);
        $planConfig = $plans[$plan] ?? null;
        if (! $planConfig || empty($planConfig['price_id'])) {
            $this->addError('plan', 'Invalid or missing plan.');

            return null;
        }

        audit_log($this->organization, auth()->user(), 'billing.checkout_started', null, null, ['plan' => $plan]);

        $billingUrl = route('billing.show', $this->organization);
        $checkout = $this->organization->newSubscription('default', $planConfig['price_id'])
            ->checkout([
                'success_url' => $billingUrl.'?checkout=success',
                'cancel_url' => $billingUrl.'?checkout=cancelled',
            ], []);

        return $checkout->redirect();
    }

    public function portal(): mixed
    {
        $this->authorize('update', $this->organization);

        if (! $this->organization->hasStripeId()) {
            $this->addError('billing', 'No billing account yet. Subscribe to a plan first.');

            return null;
        }

        audit_log($this->organization, auth()->user(), 'billing.portal_accessed');

        return $this->organization->redirectToBillingPortal(route('billing.show', $this->organization));
    }

    public function render(): View
    {
        return view('livewire.billing.show');
    }
}
