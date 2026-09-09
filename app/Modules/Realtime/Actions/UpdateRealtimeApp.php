<?php

declare(strict_types=1);

namespace App\Modules\Realtime\Actions;

use App\Modules\Billing\Services\OrganizationBillingStateComputer;
use App\Modules\Billing\Services\StripeSubscriptionSyncer;
use App\Modules\Realtime\Jobs\ProvisionRealtimeAppJob;
use App\Modules\Realtime\Models\RealtimeApp;
use App\Modules\Realtime\Services\RealtimeBackendFactory;
use InvalidArgumentException;

/**
 * Changes a managed realtime app's connection tier. Persists the new tier +
 * connection cap, then re-publishes the credential record so the relay enforces
 * the new {@see RealtimeApp::maxConnections()} cap. Billing reconciles on its
 * own: the org's per-tier app counts feed {@see OrganizationBillingStateComputer},
 * and {@see StripeSubscriptionSyncer} moves the Stripe line
 * to the new tier on the next sync.
 */
class UpdateRealtimeApp
{
    public function changeTier(RealtimeApp $app, string $tier): RealtimeApp
    {
        $tiers = (array) config('realtime.tiers', []);
        if (! array_key_exists($tier, $tiers)) {
            throw new InvalidArgumentException(__('Unknown broadcasting tier.'));
        }

        // No-op when the tier is unchanged — avoids a needless relay round-trip
        // and a no-change Stripe sync.
        if ($tier === $app->tierSlug()) {
            return $app;
        }

        $app->forceFill([
            'tier' => $tier,
            'max_connections' => (int) ($tiers[$tier]['max_connections']
                ?? config('realtime.plan.max_connections')),
        ])->save();

        // Re-sync the credential record (which carries maxConnections) to the
        // relay off-request. Idempotent and safe to retry.
        ProvisionRealtimeAppJob::dispatch($app->id);

        return $app;
    }

    /**
     * Rename the app. Purely a control-plane label — the relay keys off the
     * app's ULID and public key, neither of which move — so there is nothing to
     * re-publish and no connection is disturbed.
     */
    public function rename(RealtimeApp $app, string $name): RealtimeApp
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException(__('Give the app a name.'));
        }

        $app->forceFill(['name' => $name])->save();

        return $app;
    }

    /**
     * Stop the app accepting connections without tearing it down.
     *
     * The relay derives `enabled` from status, so publishing a paused record is
     * what actually closes the door — a status change alone would leave the
     * relay happily accepting clients. A paused app is not billable
     * ({@see RealtimeApp::isBillable()}), which makes this the honest way to
     * park an app between environments instead of deleting and re-creating it.
     */
    public function pause(RealtimeApp $app): RealtimeApp
    {
        if ($app->status === RealtimeApp::STATUS_PAUSED) {
            return $app;
        }

        $app->status = RealtimeApp::STATUS_PAUSED;
        RealtimeBackendFactory::make()->provision($app);
        $app->forceFill(['status' => RealtimeApp::STATUS_PAUSED, 'error_message' => null])->save();

        return $app;
    }

    /** Bring a paused app back. The provision job flips it to active on success. */
    public function resume(RealtimeApp $app): RealtimeApp
    {
        ProvisionRealtimeAppJob::dispatch($app->id);

        return $app;
    }

    /**
     * Issue a fresh public key and signing secret, revoking the old pair.
     *
     * The old `key:{app_key}` pointer is deleted BEFORE the new credentials are
     * written. Skipping that would leave the revoked key resolvable in KV
     * forever — the connect path looks up by key, so a "rotated" secret would
     * still authorise every existing client, which is the opposite of what
     * rotating is for.
     *
     * Deliberately synchronous up to the delete: if the relay cannot be reached
     * the rotation must not happen at all, rather than leaving the row holding
     * credentials the relay has never seen.
     */
    public function rotateCredentials(RealtimeApp $app): RealtimeApp
    {
        RealtimeBackendFactory::make()->deprovision($app);

        $app->forceFill(RealtimeApp::generateCredentials() + [
            // The app is briefly unreachable between the delete and the
            // re-publish; say so rather than showing it as active.
            'status' => RealtimeApp::STATUS_PROVISIONING,
        ])->save();

        ProvisionRealtimeAppJob::dispatch($app->id);

        return $app;
    }
}
