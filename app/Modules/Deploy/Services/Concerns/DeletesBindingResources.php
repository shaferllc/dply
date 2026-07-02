<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Services\Concerns;

use App\Actions\Servers\DeleteServerAction;
use App\Jobs\RunSiteDatabaseAdminJob;
use App\Models\Server;
use App\Models\ServerDatabase;
use App\Models\SiteBinding;
use App\Modules\Cloud\Jobs\TeardownCloudDatabaseJob;
use App\Services\Storage\ObjectStorageBucketProvisioner;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Optional teardown of the infra behind a {@see SiteBinding} when the operator
 * opts in on the detach confirm dialog.
 */
trait DeletesBindingResources
{
    private function deleteBindingResource(SiteBinding $binding): void
    {
        if (! $binding->canOfferDeleteOnDetach()) {
            return;
        }

        match ($binding->type) {
            'database' => $this->deleteDatabaseBindingResource($binding),
            'storage' => $this->deleteStorageBindingResource($binding),
            default => null,
        };
    }

    private function deleteDatabaseBindingResource(SiteBinding $binding): void
    {
        if ($binding->target_type === 'cloud_database' && filled($binding->target_id)) {
            TeardownCloudDatabaseJob::dispatch((string) $binding->target_id);

            return;
        }

        if ($binding->target_type !== 'server_database' || ! filled($binding->target_id)) {
            return;
        }

        $cfg = is_array($binding->config) ? $binding->config : [];
        $dbVmServerId = ($cfg['placement'] ?? '') === 'dedicated_vm'
            ? trim((string) ($cfg['db_vm_server_id'] ?? ''))
            : '';

        $db = ServerDatabase::query()->find($binding->target_id);

        if ($db instanceof ServerDatabase) {
            if ($dbVmServerId !== '') {
                $db->delete();
            } elseif ($binding->status === SiteBinding::STATUS_CONFIGURED) {
                RunSiteDatabaseAdminJob::dispatch(
                    RunSiteDatabaseAdminJob::OP_DROP_DATABASE,
                    (string) $db->id,
                    (string) $binding->site_id,
                    userId: Auth::id() !== null ? (string) Auth::id() : null,
                );
            } else {
                $db->delete();
            }
        }

        if ($dbVmServerId !== '') {
            $vmServer = Server::query()->find($dbVmServerId);
            if ($vmServer instanceof Server) {
                app(DeleteServerAction::class)->execute(
                    $vmServer,
                    Auth::user(),
                    ['reason' => 'binding_detach'],
                );
            }
        }
    }

    private function deleteStorageBindingResource(SiteBinding $binding): void
    {
        if (! $binding->wasProvisionedByDply()) {
            return;
        }

        $cfg = is_array($binding->config) ? $binding->config : [];
        $env = $binding->connectionEnv();
        $provider = strtolower(trim((string) ($cfg['provider'] ?? '')));
        $bucket = trim((string) ($cfg['bucket'] ?? ''));
        $region = trim((string) ($cfg['region'] ?? ($env['AWS_DEFAULT_REGION'] ?? '')));

        $disk = $binding->name ?: 's3';
        $prefix = strtoupper($disk) === 'S3' ? 'AWS_' : 'AWS_'.strtoupper($disk).'_';
        $key = trim((string) ($env[$prefix.'ACCESS_KEY_ID'] ?? $env['AWS_ACCESS_KEY_ID'] ?? ''));
        $secret = trim((string) ($env[$prefix.'SECRET_ACCESS_KEY'] ?? $env['AWS_SECRET_ACCESS_KEY'] ?? ''));

        if ($provider === '' || $bucket === '' || $key === '' || $secret === '') {
            throw new RuntimeException(__('Could not resolve bucket credentials to delete the storage resource.'));
        }

        app(ObjectStorageBucketProvisioner::class)->delete($provider, $region, $key, $secret, $bucket);
    }
}
