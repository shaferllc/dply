<?php

declare(strict_types=1);

namespace App\Modules\Queue\Services;

use App\Models\Site;
use App\Modules\Deploy\Services\ServerlessEnvironmentPreparer;
use App\Modules\Queue\Actions\CreateQueueNamespace;
use App\Modules\Queue\Models\QueueCredential;
use App\Modules\Queue\Models\QueueNamespace;
use App\Modules\Queue\Support\QueueEndpoint;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;
use Throwable;

/**
 * Gives a dply-deployed site a managed queue, and writes the env that points
 * its app at it.
 *
 * Unlike a Redis cluster or a Functions namespace there is nothing to wait
 * for: a queue namespace is a row, so this is synchronous and the site's queue
 * works on its next deploy. That immediacy is most of why dply Queue is a
 * better answer than "go provision Redis" for a broken backend.
 *
 * The env it writes deliberately does NOT touch `AWS_ACCESS_KEY_ID` /
 * `AWS_SECRET_ACCESS_KEY`. Laravel's stock `sqs` connection reads those, so
 * repurposing them would hand the app's S3 credentials to the queue. Instead
 * the injected handler registers a separate `dply` connection from the
 * `DPLY_QUEUE_*` keys below, leaving the app's own AWS config alone.
 */
final class ServerlessQueueProvisioner
{
    public function __construct(
        private readonly CreateQueueNamespace $create,
        private readonly ServerlessEnvironmentPreparer $environment,
    ) {}

    /**
     * Ensure the site has a namespace and the env to reach it.
     *
     * Returns false when dply Queue is unavailable to this org, so the caller
     * can fall back rather than treating it as an error.
     */
    public function wire(Site $site): bool
    {
        if (! $this->available($site)) {
            return false;
        }

        try {
            $namespace = $this->namespaceFor($site);

            if ($namespace === null) {
                return false;
            }

            $credential = $this->credentialFor($namespace);

            $this->environment->mergeKeys($site, [
                'QUEUE_CONNECTION' => 'dply',
                'DPLY_QUEUE_URL' => $this->endpointFor($namespace),
                'DPLY_QUEUE_KEY' => $credential['access_key_id'],
                'DPLY_QUEUE_SECRET' => $credential['secret'],
            ]);

            return true;
        } catch (Throwable $e) {
            Log::warning('queue.serverless_wire_failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Two-key gate, matching how managed serverless is offered: the org's
     * feature flag AND the platform actually being configured.
     */
    public function available(Site $site): bool
    {
        if (! (bool) config('queue_service.enabled', false)) {
            return false;
        }

        if ($this->publicUrl() === '') {
            return false;
        }

        $organization = $site->organization;

        return $organization !== null
            && Feature::for($organization)->active('surface.queue');
    }

    private function namespaceFor(Site $site): ?QueueNamespace
    {
        $existing = QueueNamespace::query()->where('site_id', $site->id)->first();

        if ($existing instanceof QueueNamespace) {
            return $existing;
        }

        $organization = $site->organization;

        if ($organization === null) {
            return null;
        }

        return $this->create->handle(
            $organization,
            (string) ($site->slug !== '' ? $site->slug : $site->name),
            $site,
        )['namespace'];
    }

    /**
     * A usable credential for the namespace.
     *
     * Reuses a live one when there is one — the secret is recoverable because
     * SigV4 needs it, so re-minting on every deploy would churn credentials
     * for no reason and burn the two-live-credential rotation budget.
     *
     * @return array{access_key_id: string, secret: string}
     */
    private function credentialFor(QueueNamespace $namespace): array
    {
        $live = $namespace->liveCredentials()->first();

        if ($live instanceof QueueCredential && (string) $live->secret !== '') {
            return [
                'access_key_id' => $live->accessKeyId(),
                'secret' => (string) $live->secret,
            ];
        }

        $minted = QueueCredential::mint($namespace, __('Deploy credential'));

        return [
            'access_key_id' => $minted['credential']->accessKeyId(),
            'secret' => $minted['plaintext'],
        ];
    }

    /** The base URL the app's queue client posts to. */
    private function endpointFor(QueueNamespace $namespace): string
    {
        return QueueEndpoint::forNamespace($namespace);
    }

    private function publicUrl(): string
    {
        return QueueEndpoint::base();
    }
}
