<?php

namespace Tests\Feature\Console;

use App\Jobs\SyncOrganizationBillingJob;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncAllOrganizationBillingCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_op_when_no_orgs_have_subscriptions(): void
    {
        Bus::fake();
        Organization::factory()->count(3)->create();

        $this->artisan('dply:billing:sync-all')
            ->expectsOutputToContain('No subscribed organizations found')
            ->assertOk();

        Bus::assertNotDispatched(SyncOrganizationBillingJob::class);
    }

    public function test_dispatches_a_sync_for_each_subscribed_org(): void
    {
        Bus::fake();

        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();
        // Unsubscribed control: should be skipped entirely.
        Organization::factory()->create();

        $this->insertRawSubscription($org1->id);
        $this->insertRawSubscription($org2->id);

        $this->artisan('dply:billing:sync-all')->assertOk();

        Bus::assertDispatchedTimes(SyncOrganizationBillingJob::class, 2);
        Bus::assertDispatched(
            SyncOrganizationBillingJob::class,
            fn (SyncOrganizationBillingJob $job) => $job->organizationId === $org1->id,
        );
        Bus::assertDispatched(
            SyncOrganizationBillingJob::class,
            fn (SyncOrganizationBillingJob $job) => $job->organizationId === $org2->id,
        );
    }

    public function test_dry_run_lists_orgs_without_dispatching(): void
    {
        Bus::fake();
        $org = Organization::factory()->create();
        $this->insertRawSubscription($org->id);

        $this->artisan('dply:billing:sync-all', ['--dry-run' => true])
            ->expectsOutputToContain('would sync org='.$org->id)
            ->expectsOutputToContain('Dry-run: 1 org')
            ->assertOk();

        Bus::assertNotDispatched(SyncOrganizationBillingJob::class);
    }

    /**
     * Insert a subscriptions row directly via DB so the test doesn't need
     * Cashier's Subscription model to support ULID primary keys (see
     * SyncOrganizationBillingJobTest class docblock for context).
     */
    private function insertRawSubscription(string $organizationId): void
    {
        DB::table('subscriptions')->insert([
            'id' => (string) Str::ulid(),
            'organization_id' => $organizationId,
            'type' => 'default',
            'stripe_id' => 'sub_test_'.Str::random(16),
            'stripe_status' => 'active',
            'stripe_price' => 'price_test_standard_base',
            'quantity' => null,
            'trial_ends_at' => null,
            'ends_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
