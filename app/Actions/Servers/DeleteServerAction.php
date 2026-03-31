<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Enums\ServerProvider;
use App\Models\Server;
use App\Models\User;
use App\Notifications\ServerRemovalExecutedNotification;
use App\Services\AwsEc2Service;
use App\Services\DigitalOceanService;
use App\Services\EquinixMetalService;
use App\Services\FlyIoService;
use App\Services\HetznerService;
use App\Services\LinodeService;
use App\Services\ScalewayService;
use App\Services\UpCloudService;
use App\Services\VultrService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

final class DeleteServerAction
{
    /**
     * Destroy cloud resources (best effort), audit, delete the server row, and optionally email org admins.
     *
     * @param  array<string, mixed>  $auditExtras  Merged into audit new_values (e.g. reason, scheduled).
     * @param  string|null  $emailContext  When set and org exists, sends {@see ServerRemovalExecutedNotification}.
     */
    public function execute(Server $server, ?User $actor, array $auditExtras = [], ?string $emailContext = null): void
    {
        $org = $server->organization;
        $serverName = $server->name;
        $organizationName = $org?->name;

        if ($org) {
            audit_log($org, $actor, 'server.deleted', $server, ['name' => $server->name], $auditExtras !== [] ? $auditExtras : null);
        }

        $this->destroyCloudResources($server);

        $server->delete();

        if ($emailContext !== null && $org && config('dply.server_deletion_notify_org_admins', true)) {
            $recipients = $org->users()->wherePivotIn('role', ['owner', 'admin'])->get();
            if ($recipients->isNotEmpty()) {
                Notification::send(
                    $recipients,
                    new ServerRemovalExecutedNotification($serverName, $organizationName, $emailContext)
                );
            }
        }
    }

    private function destroyCloudResources(Server $server): void
    {
        if ($server->provider === ServerProvider::DigitalOcean && ! empty($server->provider_id)) {
            $credential = $server->providerCredential;
            if ($credential) {
                try {
                    $do = new DigitalOceanService($credential);
                    $do->destroyDroplet((int) $server->provider_id);
                } catch (\Throwable $e) {
                    Log::warning('Failed to destroy DigitalOcean droplet on server delete.', [
                        'server_id' => $server->id,
                        'provider_id' => $server->provider_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($server->provider === ServerProvider::Hetzner && ! empty($server->provider_id)) {
            $credential = $server->providerCredential;
            if ($credential) {
                try {
                    $hetzner = new HetznerService($credential);
                    $hetzner->destroyInstance((int) $server->provider_id);
                } catch (\Throwable $e) {
                    Log::warning('Failed to destroy Hetzner instance on server delete.', [
                        'server_id' => $server->id,
                        'provider_id' => $server->provider_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if (in_array($server->provider, [ServerProvider::Linode, ServerProvider::Akamai], true) && ! empty($server->provider_id)) {
            $credential = $server->providerCredential;
            if ($credential) {
                try {
                    $linode = new LinodeService($credential);
                    $linode->destroyInstance((int) $server->provider_id);
                } catch (\Throwable $e) {
                    Log::warning('Failed to destroy Linode/Akamai instance on server delete.', [
                        'server_id' => $server->id,
                        'provider_id' => $server->provider_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($server->provider === ServerProvider::Vultr && ! empty($server->provider_id)) {
            $credential = $server->providerCredential;
            if ($credential) {
                try {
                    $vultr = new VultrService($credential);
                    $vultr->destroyInstance($server->provider_id);
                } catch (\Throwable $e) {
                    Log::warning('Failed to destroy Vultr instance on server delete.', [
                        'server_id' => $server->id,
                        'provider_id' => $server->provider_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($server->provider === ServerProvider::Scaleway && ! empty($server->provider_id)) {
            $credential = $server->providerCredential;
            if ($credential) {
                try {
                    $scw = new ScalewayService($credential);
                    $scw->destroyServer($server->region, $server->provider_id);
                } catch (\Throwable $e) {
                    Log::warning('Failed to destroy Scaleway instance on server delete.', [
                        'server_id' => $server->id,
                        'provider_id' => $server->provider_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($server->provider === ServerProvider::UpCloud && ! empty($server->provider_id)) {
            $credential = $server->providerCredential;
            if ($credential) {
                try {
                    $upcloud = new UpCloudService($credential);
                    $upcloud->destroyServer($server->provider_id);
                } catch (\Throwable $e) {
                    Log::warning('Failed to destroy UpCloud server on server delete.', [
                        'server_id' => $server->id,
                        'provider_id' => $server->provider_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($server->provider === ServerProvider::EquinixMetal && ! empty($server->provider_id)) {
            $credential = $server->providerCredential;
            if ($credential) {
                try {
                    $metal = new EquinixMetalService($credential);
                    $metal->destroyDevice($server->provider_id);
                } catch (\Throwable $e) {
                    Log::warning('Failed to destroy Equinix Metal device on server delete.', [
                        'server_id' => $server->id,
                        'provider_id' => $server->provider_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($server->provider === ServerProvider::FlyIo && ! empty($server->provider_id)) {
            $credential = $server->providerCredential;
            $appName = $server->meta['app_name'] ?? null;
            if ($credential && $appName) {
                try {
                    $fly = new FlyIoService($credential);
                    $fly->deleteMachine($appName, $server->provider_id);
                    $fly->deleteApp($appName);
                } catch (\Throwable $e) {
                    Log::warning('Failed to destroy Fly.io machine/app on server delete.', [
                        'server_id' => $server->id,
                        'provider_id' => $server->provider_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($server->provider === ServerProvider::Aws && ! empty($server->provider_id)) {
            $credential = $server->providerCredential;
            if ($credential) {
                try {
                    $aws = new AwsEc2Service($credential, $server->region);
                    $aws->terminateInstances($server->provider_id);
                    $keyName = $server->meta['key_name'] ?? null;
                    if ($keyName) {
                        try {
                            $aws->deleteKeyPair($keyName);
                        } catch (\Throwable) {
                            //
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning('Failed to destroy AWS EC2 instance on server delete.', [
                        'server_id' => $server->id,
                        'provider_id' => $server->provider_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }
}
