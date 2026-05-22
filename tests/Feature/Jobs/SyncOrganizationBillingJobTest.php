<?php

namespace Tests\Feature\Jobs\SyncOrganizationBillingJobTest;

use App\Jobs\SyncOrganizationBillingJob;
use App\Models\Organization;
use App\Models\Subscription;
use App\Services\Billing\DesiredBillingState;
use App\Services\Billing\OrganizationBillingStateComputer;
use App\Services\Billing\StripeSubscriptionSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;

uses(RefreshDatabase::class);

beforeEach(function () {
    Config::set('subscription.standard.stripe.base_monthly', $this->basePriceId);
});

test('handle is a no op when organization does not exist', function () {
    $fake = bindFakeSyncer();

    (new SyncOrganizationBillingJob('01nonexistentorganizationid'))->handle(
        app(OrganizationBillingStateComputer::class),
        $fake,
    );

    expect($fake->calls)->toBeEmpty();
});

test('handle is a no op when organization has no standard subscription', function () {
    $org = Organization::factory()->create();
    $fake = bindFakeSyncer();

    (new SyncOrganizationBillingJob($org->id))->handle(
        app(OrganizationBillingStateComputer::class),
        $fake,
    );

    expect($fake->calls)->toBeEmpty();
});

test('invokes syncer when organization has active standard subscription', function () {
    $org = Organization::factory()->create();
    Subscription::factory()
        ->withPrice($this->basePriceId)
        ->active()
        ->create(['organization_id' => $org->id]);

    $fake = bindFakeSyncer();

    (new SyncOrganizationBillingJob($org->id))->handle(
        app(OrganizationBillingStateComputer::class),
        $fake,
    );

    expect($fake->calls)->toHaveCount(1);
    expect($fake->calls[0]['organization']->id)->toBe($org->id);
    expect($fake->calls[0]['desired'])->toBeInstanceOf(DesiredBillingState::class);
});

test('skips when subscription is canceled', function () {
    $org = Organization::factory()->create();
    Subscription::factory()
        ->withPrice($this->basePriceId)
        ->canceled()
        ->create(['organization_id' => $org->id]);

    $fake = bindFakeSyncer();

    (new SyncOrganizationBillingJob($org->id))->handle(
        app(OrganizationBillingStateComputer::class),
        $fake,
    );

    expect($fake->calls)->toBeEmpty();
});

test('job is unique by organization id', function () {
    $org = Organization::factory()->create();
    $job = new SyncOrganizationBillingJob($org->id);

    expect($job->uniqueId())->toBe($org->id);
});

test('job can be dispatched', function () {
    Bus::fake();
    $org = Organization::factory()->create();

    SyncOrganizationBillingJob::dispatch($org->id);

    Bus::assertDispatched(
        SyncOrganizationBillingJob::class,
        fn (SyncOrganizationBillingJob $j) => $j->organizationId === $org->id,
    );
});

function bindFakeSyncer(): object
{
    $fake = new class extends StripeSubscriptionSyncer
    {
        public array $calls = [];

        public function reconcile(Organization $organization, DesiredBillingState $desired): array
        {
            $this->calls[] = ['organization' => $organization, 'desired' => $desired];

            return [];
        }
    };
    app()->instance(StripeSubscriptionSyncer::class, $fake);

    return $fake;
}
