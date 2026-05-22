<?php

namespace Tests\Feature\Jobs;

use App\Jobs\SyncOrganizationBillingJob;
use App\Models\Organization;
use App\Models\Subscription;
use App\Services\Billing\DesiredBillingState;
use App\Services\Billing\OrganizationBillingStateComputer;
use App\Services\Billing\StripeSubscriptionSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SyncOrganizationBillingJobTest extends TestCase
{
    use RefreshDatabase;

    private string $basePriceId = 'price_test_standard_base';

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('subscription.standard.stripe.base_monthly', $this->basePriceId);
    }

    public function test_handle_is_a_no_op_when_organization_does_not_exist(): void
    {
        $fake = $this->bindFakeSyncer();

        (new SyncOrganizationBillingJob('01nonexistentorganizationid'))->handle(
            app(OrganizationBillingStateComputer::class),
            $fake,
        );

        $this->assertEmpty($fake->calls);
    }

    public function test_handle_is_a_no_op_when_organization_has_no_standard_subscription(): void
    {
        $org = Organization::factory()->create();
        $fake = $this->bindFakeSyncer();

        (new SyncOrganizationBillingJob($org->id))->handle(
            app(OrganizationBillingStateComputer::class),
            $fake,
        );

        $this->assertEmpty($fake->calls);
    }

    public function test_invokes_syncer_when_organization_has_active_standard_subscription(): void
    {
        $org = Organization::factory()->create();
        Subscription::factory()
            ->withPrice($this->basePriceId)
            ->active()
            ->create(['organization_id' => $org->id]);

        $fake = $this->bindFakeSyncer();

        (new SyncOrganizationBillingJob($org->id))->handle(
            app(OrganizationBillingStateComputer::class),
            $fake,
        );

        $this->assertCount(1, $fake->calls);
        $this->assertSame($org->id, $fake->calls[0]['organization']->id);
        $this->assertInstanceOf(DesiredBillingState::class, $fake->calls[0]['desired']);
    }

    public function test_skips_when_subscription_is_canceled(): void
    {
        $org = Organization::factory()->create();
        Subscription::factory()
            ->withPrice($this->basePriceId)
            ->canceled()
            ->create(['organization_id' => $org->id]);

        $fake = $this->bindFakeSyncer();

        (new SyncOrganizationBillingJob($org->id))->handle(
            app(OrganizationBillingStateComputer::class),
            $fake,
        );

        $this->assertEmpty($fake->calls);
    }

    public function test_job_is_unique_by_organization_id(): void
    {
        $org = Organization::factory()->create();
        $job = new SyncOrganizationBillingJob($org->id);

        $this->assertSame($org->id, $job->uniqueId());
    }

    public function test_job_can_be_dispatched(): void
    {
        Bus::fake();
        $org = Organization::factory()->create();

        SyncOrganizationBillingJob::dispatch($org->id);

        Bus::assertDispatched(
            SyncOrganizationBillingJob::class,
            fn (SyncOrganizationBillingJob $j) => $j->organizationId === $org->id,
        );
    }

    private function bindFakeSyncer(): object
    {
        $fake = new class extends StripeSubscriptionSyncer
        {
            /** @var array<int, array{organization: Organization, desired: DesiredBillingState}> */
            public array $calls = [];

            public function reconcile(Organization $organization, DesiredBillingState $desired): array
            {
                $this->calls[] = ['organization' => $organization, 'desired' => $desired];

                return [];
            }
        };

        $this->app->instance(StripeSubscriptionSyncer::class, $fake);

        return $fake;
    }
}
