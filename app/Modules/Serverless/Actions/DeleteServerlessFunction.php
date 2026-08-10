<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Actions;

use App\Models\Server;
use App\Models\Site;
use App\Modules\Cloud\Services\DigitalOceanService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tear down a serverless function — the teardown counterpart of
 * {@see CreateServerlessFunction}.
 *
 * Create stands up two things per function: a Site (the function) and a
 * Server (the DigitalOcean Functions namespace it lives in). Deleting only
 * the Site would leave the namespace running on the customer's DigitalOcean
 * account, still billing, with nothing in dply pointing at it. So this
 * removes both, plus the remote namespace itself.
 *
 * Three things it deliberately does NOT do:
 *
 *  - Delete a namespace that still hosts other functions. Create makes one
 *    namespace per function, but ProvisionServerlessHostJob is written to
 *    deploy every configured Site on a host, so a shared host is possible.
 *    The host only goes when this was its last function.
 *  - Delete a dply-managed namespace. Managed functions run inside dply's own
 *    shared platform namespace — dropping it would take down every managed
 *    customer's functions at once.
 *  - Fail the local delete because the remote call failed. A revoked or
 *    rate-limited credential must not strand a function in dply forever; the
 *    remote error is logged and reported, and the local rows still go.
 */
class DeleteServerlessFunction
{
    /**
     * @return array{namespace_deleted: bool, host_deleted: bool, remote_error: ?string}
     */
    public function handle(Site $site): array
    {
        // Looked up by id rather than through the relation: a Site's host is
        // genuinely optional (the column is nullable and an orphaned row is
        // exactly the kind of half-created state this action exists to clear),
        // and the relation accessor is typed as always-present.
        $siteId = (string) $site->getKey();
        $server = Server::query()->whereKey($site->server_id)->first();

        // Resolve everything that depends on the host BEFORE the site row goes,
        // so the "was this the last function?" question is answered against the
        // state the caller saw.
        $hostDeletable = $server !== null && $this->isSoleFunctionOnHost($server, $siteId);
        $namespace = $this->byoNamespace($server);

        $remoteError = null;
        $namespaceDeleted = false;

        // Remote teardown first: if the namespace delete is going to fail, the
        // operator should hear about it in the same breath as the local delete,
        // not discover an orphan on their DigitalOcean bill next month.
        if ($hostDeletable && $namespace !== null) {
            try {
                $namespaceDeleted = (new DigitalOceanService($namespace['credential']))
                    ->deleteFunctionsNamespace($namespace['id']);
            } catch (Throwable $e) {
                $remoteError = $e->getMessage();
                Log::error('serverless.namespace.delete_failed', [
                    'server_id' => $site->server_id,
                    'namespace' => $namespace['id'],
                    'error' => $remoteError,
                ]);
            }
        }

        // Site::deleting fans out to SiteRelationPurger for the non-cascading
        // relations, so the site delete is the single chokepoint — don't
        // hand-roll cleanup here.
        DB::transaction(function () use ($site, $server, $hostDeletable): void {
            $site->delete();

            // $hostDeletable is only ever true when $server resolved.
            if ($hostDeletable) {
                $server->delete();
            }
        });

        return [
            'namespace_deleted' => $namespaceDeleted,
            'host_deleted' => $hostDeletable,
            'remote_error' => $remoteError,
        ];
    }

    /**
     * True when $siteId is the only function attached to this host, so the
     * host (and its namespace) can go with it.
     */
    private function isSoleFunctionOnHost(Server $server, string $siteId): bool
    {
        return ! $server->sites()
            ->whereKeyNot($siteId)
            ->exists();
    }

    /**
     * The customer-owned namespace to destroy, or null when there is nothing
     * safe to destroy — a managed (shared platform) host, a host that never
     * finished provisioning, or one whose credential has since been removed.
     *
     * @return array{id: string, credential: \App\Models\ProviderCredential}|null
     */
    private function byoNamespace(?Server $server): ?array
    {
        if ($server === null) {
            return null;
        }

        $meta = is_array($server->meta) ? $server->meta : [];

        // dply's own shared namespace — never ours to delete.
        if (! empty($meta['serverless_managed'] ?? null)) {
            return null;
        }

        $namespaceId = (string) ($meta['digitalocean_functions']['namespace'] ?? '');
        $credential = $server->providerCredential;

        if ($namespaceId === '' || $credential === null) {
            return null;
        }

        return ['id' => $namespaceId, 'credential' => $credential];
    }
}
