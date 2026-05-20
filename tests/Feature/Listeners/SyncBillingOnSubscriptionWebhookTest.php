<?php

namespace Tests\Feature\Listeners;

use App\Jobs\SyncOrganizationBillingJob;
use App\Listeners\SyncBillingOnSubscriptionWebhook;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Cashier\Events\WebhookReceived;
use Tests\TestCase;

class SyncBillingOnSubscriptionWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_sync_on_subscription_created_event(): void
    {
        Bus::fake();
        $org = Organization::factory()->create(['stripe_id' => 'cus_test_123']);

        $this->fireWebhook('customer.subscription.created', $org->stripe_id);

        Bus::assertDispatched(
            SyncOrganizationBillingJob::class,
            fn (SyncOrganizationBillingJob $job) => $job->organizationId === $org->id,
        );
    }

    public function test_dispatches_sync_on_subscription_updated_event(): void
    {
        Bus::fake();
        $org = Organization::factory()->create(['stripe_id' => 'cus_test_456']);

        $this->fireWebhook('customer.subscription.updated', $org->stripe_id);

        Bus::assertDispatchedTimes(SyncOrganizationBillingJob::class, 1);
    }

    public function test_ignores_unrelated_events(): void
    {
        Bus::fake();
        $org = Organization::factory()->create(['stripe_id' => 'cus_test_789']);

        $this->fireWebhook('invoice.payment_succeeded', $org->stripe_id);

        Bus::assertNotDispatched(SyncOrganizationBillingJob::class);
    }

    public function test_no_op_when_no_organization_matches_customer_id(): void
    {
        Bus::fake();

        $this->fireWebhook('customer.subscription.updated', 'cus_unknown');

        Bus::assertNotDispatched(SyncOrganizationBillingJob::class);
    }

    public function test_no_op_when_payload_lacks_customer_id(): void
    {
        Bus::fake();
        Organization::factory()->create(['stripe_id' => 'cus_test_x']);

        app(SyncBillingOnSubscriptionWebhook::class)->handle(new WebhookReceived([
            'type' => 'customer.subscription.created',
            'data' => ['object' => []],
        ]));

        Bus::assertNotDispatched(SyncOrganizationBillingJob::class);
    }

    private function fireWebhook(string $type, string $customerId): void
    {
        app(SyncBillingOnSubscriptionWebhook::class)->handle(new WebhookReceived([
            'type' => $type,
            'data' => ['object' => ['customer' => $customerId]],
        ]));
    }
}
