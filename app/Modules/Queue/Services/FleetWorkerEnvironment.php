<?php

declare(strict_types=1);

namespace App\Modules\Queue\Services;

use App\Models\ServiceCredential;
use App\Models\Site;
use App\Modules\Queue\Actions\MintQueueCredential;
use App\Modules\Queue\Models\ManagedQueueFleet;
use App\Modules\Queue\Models\QueueNamespace;
use App\Modules\Queue\Support\QueueEndpoint;
use App\Services\Sites\DotEnvFileParser;
use RuntimeException;

/**
 * The env a managed worker runs with.
 *
 * Two layers. The site's own environment, so the app boots the way it does on
 * its own box — without it the container claims a job and dies on its first
 * query. Then the queue wiring on top: the same keys a deployed app receives,
 * because a dply-owned worker authenticates over the public SQS-compatible
 * endpoint exactly like a customer's app would. Giving the managed path a
 * private shortcut into the store would mean the endpoint customers depend on
 * is the one dply itself never exercises.
 */
class FleetWorkerEnvironment
{
    public function __construct(
        private readonly DotEnvFileParser $parser,
    ) {}

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

        // The API base, NOT the per-namespace URL. The agent posts to
        // `$url . '/' . $queue` and identifies the namespace by its bearer
        // token, so a URL carrying the namespace id produces
        // `/api/queue/v1/<namespace>/<queue>` — two segments where the route
        // takes one. It does not 404 loudly either: the worker just polls a
        // path that never returns messages and drains nothing, silently.
        // ManagedQueueConnector writes the base for exactly this reason.
        $endpoint = QueueEndpoint::base();

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
            // Failures go where the jobs go — the same line
            // ManagedQueueConnector writes into a deployed site's .env. Without
            // it Laravel falls back to the `database` failed-job driver, so a
            // worker whose app cannot reach a database dies on the FIRST failed
            // job instead of recording it, and any failure that does record
            // lands somewhere the dply Failed jobs tab cannot read.
            'QUEUE_FAILED_DRIVER' => 'dply',
            'DPLY_QUEUE_URL' => $endpoint,
            // The name the agent package actually reads, and the same one
            // ManagedQueueConnector writes into a deployed site's .env. Without
            // it the provider still registers the connection — it only checks
            // DPLY_QUEUE_URL — and then authenticates with an empty token, so
            // the worker runs, logs nothing, and drains nothing. A silent
            // no-op is the worst shape this failure could have taken.
            'DPLY_QUEUE_TOKEN' => $credential['secret'],
            // Kept for the SigV4 surface: the SQS-compatible endpoint signs
            // with a key/secret pair rather than a bearer token.
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
