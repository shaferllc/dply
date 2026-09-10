<?php

declare(strict_types=1);

namespace App\Modules\Queue\Services;

use App\Modules\Queue\Models\ManagedQueueFleet;
use App\Models\ServiceCredential;
use App\Modules\Queue\Actions\MintQueueCredential;
use App\Modules\Queue\Models\QueueNamespace;
use App\Modules\Queue\Support\QueueEndpoint;
use App\Models\Site;
use App\Services\Sites\DotEnvFileParser;
use RuntimeException;

/**
 * The env a managed worker needs to reach its own queue.
 *
 * Deliberately the same four keys a deployed app receives: a dply-owned
 * worker authenticates over the public SQS-compatible endpoint exactly like
 * a customer's own app would. Giving the managed path a private shortcut
 * into the store would mean the endpoint customers depend on is the one
 * dply itself never exercises.
 */
class FleetWorkerEnvironment
{
    /**
     * @return array<string, string>
     *
     * @throws RuntimeException when dply has no publicly reachable endpoint
     */
    public function for(ManagedQueueFleet $fleet): array
    {
        $namespace = $fleet->namespace;

        if (! $namespace instanceof QueueNamespace) {
            throw new RuntimeException('Fleet '.$fleet->id.' has no namespace.');
        }

        $endpoint = QueueEndpoint::forNamespace($namespace);

        if ($endpoint === '') {
            // A worker with no endpoint would boot, fail every claim, and look
            // like a broken queue. Refusing to start is the honest failure.
            throw new RuntimeException('dply Queue has no public URL configured; cannot start workers.');
        }

        $credential = $this->credential($namespace);

        // The app's own configuration first, dply's queue wiring on top.
        //
        // Four variables alone boot the customer's app with no database
        // connection and no APP_KEY, so the container starts, connects to the
        // queue, claims a job and dies on its first query — which reads as a
        // broken queue rather than a missing environment. The worker needs the
        // same environment the site's own workers run with.
        return array_merge($this->siteEnvironment($namespace), [
            'QUEUE_CONNECTION' => 'dply',
            'DPLY_QUEUE_URL' => $endpoint,
            'DPLY_QUEUE_KEY' => $credential['access_key_id'],
            'DPLY_QUEUE_SECRET' => $credential['secret'],
        ]);
    }

    /**
     * The site's own environment, as dply holds it.
     *
     * Only for a namespace dply deploys — an externally hosted app keeps its
     * environment to itself, and dply has nothing to inject.
     *
     * `QUEUE_*` is dropped rather than overridden one key at a time: a site that
     * was on Redis carries `REDIS_QUEUE` and friends, and a worker that quietly
     * honoured one of them would drain the wrong queue while looking healthy.
     *
     * @return array<string, string>
     */
    private function siteEnvironment(QueueNamespace $namespace): array
    {
        $site = $namespace->site;

        if (! $site instanceof Site) {
            return [];
        }

        $variables = $this->parser->parse((string) ($site->env_file_content ?? ''))['variables'];

        $out = [];
        foreach ($variables as $key => $value) {
            $key = (string) $key;

            if (str_starts_with($key, 'QUEUE_') || str_starts_with($key, 'DPLY_QUEUE_')) {
                continue;
            }

            $out[$key] = (string) $value;
        }

        return $out;
    }

    /**
     * Reuse the namespace's live credential, minting one only if there is
     * none.
     *
     * A credential per worker would multiply rows by the scale factor and
     * make revocation a fleet-wide sweep instead of one row — and the
     * namespace epoch already provides the "revoke everything" lever.
     *
     * @return array{access_key_id: string, secret: string}
     */
    private function credential(QueueNamespace $namespace): array
    {
        $live = $namespace->liveCredentials()->first();

        if ($live instanceof ServiceCredential && (string) $live->secret !== '') {
            return [
                'access_key_id' => $live->accessKeyId(),
                'secret' => (string) $live->secret,
            ];
        }

        $minted = (new MintQueueCredential)->handle($namespace, __('Managed worker credential'));

        return [
            'access_key_id' => $minted['credential']->accessKeyId(),
            'secret' => $minted['plaintext'],
        ];
    }
}
