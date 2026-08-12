<?php

declare(strict_types=1);

namespace App\Modules\Queue\Services;

use App\Models\Organization;
use App\Models\OrganizationBillingSnapshot;
use App\Modules\Notifications\Services\NotificationPublisher;
use App\Modules\Queue\Models\QueueNamespace;
use App\Modules\Queue\Support\QueueTier;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells a customer when a queue namespace starts or stops costing money.
 *
 * dply Queue derives billability from the site a namespace serves rather than
 * stamping it at creation ({@see QueueNamespace::isBillable()}). That is the
 * right model — the price should follow what the queue currently serves — but
 * it has one sharp edge: converting a site from Serverless to Cloud moves its
 * queue from $0 to its tier price with nobody having touched the queue. The
 * observer cannot see it, because no row on the namespace changed.
 *
 * So the transition is found by diffing the set of namespaces we billed at the
 * last snapshot against the set we would bill now. No new state: the previous
 * set is already recorded in the snapshot's `fleet_counts`, which exists to be
 * the record of what an org was charged for in a cycle.
 *
 * See docs/adr/managed-services-tier.md, decision 7.
 */
final class QueueBillabilityFlipNotifier
{
    public function __construct(private readonly NotificationPublisher $publisher) {}

    /**
     * Compare the org's current billable set against its last snapshot and
     * publish one notification per namespace that moved.
     *
     * Deliberately never throws. This runs off the back of a billing sync; a
     * failure to *describe* a price change must not roll back the sync that
     * applied it.
     *
     * @param  list<string>  $currentBillableIds  Ids the billing pass just counted.
     */
    public function notifyFlips(Organization $organization, array $currentBillableIds): void
    {
        if (! (bool) config('queue_service.billing.enabled', false)) {
            return;
        }

        try {
            $previous = $this->previousBillableIds($organization);

            // No prior snapshot means we have no idea what changed. Treating an
            // absent baseline as "everything just became billable" would blast
            // every customer on the first run after this ships.
            if ($previous === null) {
                return;
            }

            $current = array_values(array_unique(array_map(strval(...), $currentBillableIds)));

            $becameBillable = array_diff($current, $previous);
            $becameFree = array_diff($previous, $current);

            foreach ($becameBillable as $id) {
                $this->publishFor($id, billable: true);
            }

            foreach ($becameFree as $id) {
                $this->publishFor($id, billable: false);
            }
        } catch (Throwable $e) {
            Log::warning('queue.billability_flip_notify_failed', [
                'organization_id' => $organization->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The billable set as of the org's most recent snapshot, or null when there
     * is no usable baseline.
     *
     * A snapshot written before this key existed returns null rather than an
     * empty list, because "we did not record it" and "we billed nothing" would
     * otherwise be indistinguishable — and the second reading sends a false
     * "started billing" for every existing namespace.
     *
     * @return list<string>|null
     */
    private function previousBillableIds(Organization $organization): ?array
    {
        $snapshot = OrganizationBillingSnapshot::query()
            ->where('organization_id', $organization->id)
            ->orderByDesc('snapshot_date')
            ->first();

        if (! $snapshot instanceof OrganizationBillingSnapshot) {
            return null;
        }

        $counts = (array) $snapshot->fleet_counts;

        if (! array_key_exists('queue_billable_namespace_ids', $counts)) {
            return null;
        }

        $ids = $counts['queue_billable_namespace_ids'];

        if (! is_array($ids)) {
            return null;
        }

        return array_values(array_unique(array_map(strval(...), $ids)));
    }

    private function publishFor(string $namespaceId, bool $billable): void
    {
        $namespace = QueueNamespace::query()->with('site:id,name')->find($namespaceId);

        if (! $namespace instanceof QueueNamespace) {
            // Deleted between the snapshot and now. Its line is already gone;
            // announcing a price change on a queue that no longer exists would
            // only confuse.
            return;
        }

        $tier = QueueTier::resolve($namespace->tier);
        $price = '$'.number_format($tier->priceCents / 100, 2);
        $siteName = $namespace->site?->name;

        if ($billable) {
            $this->publisher->publish(
                eventKey: 'queue.namespace.became_billable',
                subject: $namespace,
                title: __('Queue “:name” now bills at :price/month', [
                    'name' => $namespace->name,
                    'price' => $price,
                ]),
                // Name the cause, not just the effect: the customer did not
                // change the queue, so "your queue costs money now" with no
                // reason reads as a price rise rather than a consequence.
                body: $siteName !== null
                    ? __('Queue namespaces are included free while they serve a dply Serverless site. “:site” no longer runs on Serverless, so this namespace has moved onto its :tier tier at :price/month.', [
                        'site' => $siteName,
                        'tier' => $tier->label,
                        'price' => $price,
                    ])
                    : __('This namespace is no longer attached to a dply Serverless site, so it has moved onto its :tier tier at :price/month.', [
                        'tier' => $tier->label,
                        'price' => $price,
                    ]),
                url: route('queues.show', $namespace),
                metadata: [
                    'namespace_id' => (string) $namespace->id,
                    'tier' => $tier->slug,
                    'price_cents' => $tier->priceCents,
                    'site_id' => $namespace->site_id,
                ],
            );

            return;
        }

        $this->publisher->publish(
            eventKey: 'queue.namespace.became_free',
            subject: $namespace,
            title: __('Queue “:name” is now included free', ['name' => $namespace->name]),
            body: $siteName !== null
                ? __('“:site” runs on dply Serverless, so this queue namespace is included at no charge and its monthly fee has been removed.', ['site' => $siteName])
                : __('This queue namespace is included at no charge and its monthly fee has been removed.'),
            url: route('queues.show', $namespace),
            metadata: [
                'namespace_id' => (string) $namespace->id,
                'site_id' => $namespace->site_id,
            ],
        );
    }
}
