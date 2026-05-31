<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Enums\ServerProvider;
use App\Models\Server;
use App\Models\User;
use App\Notifications\ServerRemovalExecutedNotification;
use App\Services\AwsEc2ServiceFactory;
use App\Services\AzureComputeService;
use App\Services\DigitalOceanService;
use App\Services\EquinixMetalService;
use App\Services\FlyIoService;
use App\Services\GcpComputeService;
use App\Services\HetznerService;
use App\Services\LinodeService;
use App\Services\Notifications\NotificationPublisher;
use App\Services\OracleComputeService;
use App\Services\ScalewayService;
use App\Services\UpCloudService;
use App\Services\VultrService;
use App\Support\Servers\ServerHostingPlatformContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

final class DeleteServerAction
{
    public function __construct(
        private readonly NotificationPublisher $publisher,
    ) {}

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
                $event = $this->publisher->publish(
                    eventKey: 'server.removal.executed',
                    subject: null,
                    title: '['.config('app.name').'] '.$serverName.' removed',
                    body: $emailContext,
                    url: null,
                    metadata: [
                        'server_name' => $serverName,
                        'organization_name' => $organizationName,
                    ],
                    contextOverrides: [
                        'organization_id' => $org->id,
                    ],
                    actor: $actor,
                    recipientUsers: $recipients->pluck('id')->all(),
                );

                Notification::send($recipients, new ServerRemovalExecutedNotification($event));
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
            // Managed VMs run on dply's own Hetzner project, so teardown MUST use
            // the platform token (the customer has no credential) — otherwise the
            // dply-owned VM keeps costing us money after cancellation.
            $hetzner = null;
            if ($server->usesManagedHosting()) {
                $platform = ServerHostingPlatformContext::fromConfig();
                if ($platform->configured()) {
                    $hetzner = $platform->hetzner();
                }
            } elseif ($server->providerCredential) {
                $hetzner = new HetznerService($server->providerCredential);
            }

            if ($hetzner) {
                try {
                    $hetzner->destroyInstance((int) $server->provider_id);
                } catch (\Throwable $e) {
                    Log::warning('Failed to destroy Hetzner instance on server delete.', [
                        'server_id' => $server->id,
                        'provider_id' => $server->provider_id,
                        'managed' => $server->usesManagedHosting(),
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
                    $aws = app(AwsEc2ServiceFactory::class)->make($credential, $server->region);
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

        if ($server->provider === ServerProvider::Azure && ! empty($server->provider_id)) {
            $credential = $server->providerCredential;
            if ($credential) {
                $azureMeta = is_array($server->meta['azure'] ?? null) ? $server->meta['azure'] : [];
                $resourceGroup = (string) ($azureMeta['resource_group'] ?? ($credential->credentials['resource_group'] ?? config('services.azure.default_resource_group', 'dply')));
                $vmName = (string) ($azureMeta['vm_name'] ?? $server->provider_id);
                if ($resourceGroup !== '' && $vmName !== '') {
                    try {
                        (new AzureComputeService($credential))->deleteVm($resourceGroup, $vmName);
                    } catch (\Throwable $e) {
                        Log::warning('Failed to destroy Azure VM on server delete.', [
                            'server_id' => $server->id,
                            'provider_id' => $server->provider_id,
                            'resource_group' => $resourceGroup,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        if ($server->provider === ServerProvider::Oracle && ! empty($server->provider_id)) {
            $credential = $server->providerCredential;
            if ($credential) {
                try {
                    (new OracleComputeService($credential))->terminateInstance($server->provider_id);
                } catch (\Throwable $e) {
                    Log::warning('Failed to destroy Oracle instance on server delete.', [
                        'server_id' => $server->id,
                        'provider_id' => $server->provider_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($server->provider === ServerProvider::Gcp && ! empty($server->provider_id)) {
            $credential = $server->providerCredential;
            if ($credential) {
                try {
                    $gcp = new GcpComputeService($credential);
                    $gcp->deleteInstance($server->region, $server->provider_id);
                } catch (\Throwable $e) {
                    Log::warning('Failed to destroy GCP instance on server delete.', [
                        'server_id' => $server->id,
                        'provider_id' => $server->provider_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }
}
