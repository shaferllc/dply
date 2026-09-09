<?php

declare(strict_types=1);

namespace App\Modules\Database\Actions;

use App\Actions\Servers\StoreServerFromCreateForm;
use App\Enums\ServerProvider;
use App\Livewire\Forms\ServerCreateForm;
use App\Models\ServerCacheService;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Models\User;
use App\Modules\Database\Jobs\ProvisionDedicatedRedisVmJob;
use App\Modules\Database\Support\DedicatedRedisVm;
use App\Modules\Deploy\Services\SiteBindingManager;
use App\Support\Servers\DedicatedVmPlacement;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Component;
use RuntimeException;

/**
 * Provisions a brand-new server on the customer's connected provider whose
 * sole job is Redis, then attaches it as this site's redis binding.
 *
 * Reuses the existing customer-connected create pipeline
 * ({@see StoreServerFromCreateForm}) as a `redis`-role / `redis_server`
 * profile box — the same recipe as Create server → Cache / key-value server.
 * {@see ProvisionDedicatedRedisVmJob} waits for setup, then injects REDIS_*.
 */
class CreateDedicatedRedisVm
{
    /**
     * @param  array<string, mixed>  $form  The binding modal's form (name, vm_size).
     */
    public function handle(Component $component, Site $site, array $form): SiteBinding
    {
        $appServer = $site->server;
        if ($appServer === null) {
            throw new RuntimeException(__('This site has no server to anchor a Redis VM to.'));
        }
        if (! DedicatedRedisVm::eligible($appServer)) {
            throw new RuntimeException(__('A dedicated Redis server needs a connected cloud provider on this server.'));
        }

        $manager = app(SiteBindingManager::class);
        $connection = $manager->resolveInstanceConnectionName($site, 'redis', ['connection' => $form['connection'] ?? '']);
        $isPrimary = $connection === '';
        if ($isPrimary) {
            $manager->assertNoOtherPrimaryInstance($site, 'redis');
        }

        $engine = strtolower(trim((string) ($form['engine'] ?? 'redis')));
        if (! in_array($engine, DedicatedRedisVm::supportedEngines(), true)) {
            throw new InvalidArgumentException(__('A dedicated Redis server only supports Redis.'));
        }

        $name = trim((string) ($form['name'] ?? ''));
        if ($name === '' || preg_match('/^[a-zA-Z0-9_]+$/', $name) !== 1) {
            throw new InvalidArgumentException(__('Cluster name must be alphanumeric/underscore.'));
        }

        $size = trim((string) ($form['vm_size'] ?? ''));
        if ($size === '') {
            throw new InvalidArgumentException(__('Choose a size for the Redis server.'));
        }

        $user = auth()->user();
        if (! $user instanceof User) {
            throw new RuntimeException(__('You must be signed in to provision a Redis server.'));
        }
        $org = $site->organization ?? $user->currentOrganization();
        if ($org === null) {
            throw new RuntimeException(__('No organization for this site.'));
        }

        $password = Str::password(24, symbols: false);
        $allowedFrom = (string) ($appServer->private_ip_address ?: $appServer->ip_address ?: '');
        $placement = DedicatedVmPlacement::for($appServer, $org);
        DedicatedVmPlacement::assertSizeAvailable($size, $placement['sizes'], $placement['region']);

        $createForm = new ServerCreateForm($component, 'dedicatedRedisForm');
        $createForm->mode = 'provider';
        $createForm->type = $appServer->provider->value;
        $createForm->provider_credential_id = (string) $appServer->provider_credential_id;
        $createForm->name = Str::limit(($site->slug ?: 'site').'-redis', 60, '');
        $createForm->region = $placement['region'];
        $createForm->size = $size;
        $createForm->server_role = 'redis';
        $createForm->install_profile = 'redis_server';
        $createForm->cache_service = 'redis';
        $createForm->webserver = 'none';
        $createForm->php_version = 'none';
        $createForm->database = 'none';
        $createForm->cache_remote_access = true;
        $createForm->cache_allowed_from = $allowedFrom;
        $createForm->cache_require_password = true;
        $createForm->cache_password = $password;

        if ($appServer->provider === ServerProvider::DigitalOcean) {
            $vpc = (string) ($appServer->privateNetwork->provider_id ?? '');
            if ($vpc !== '') {
                $createForm->do_vpc_uuid = $vpc;
            }
        } elseif ($appServer->provider === ServerProvider::Hetzner) {
            $createForm->hetzner_network_id = (string) ($appServer->hetzner_network_id ?? '');
        }

        $cacheServer = app(StoreServerFromCreateForm::class)->handle($user, $org, $createForm);

        $service = ServerCacheService::query()->create([
            'server_id' => $cacheServer->id,
            'engine' => 'redis',
            'name' => ServerCacheService::DEFAULT_INSTANCE_NAME,
            'status' => ServerCacheService::STATUS_INSTALLING,
            'port' => ServerCacheService::defaultPortFor('redis'),
            'auth_password' => $password,
        ]);

        $binding = SiteBinding::query()->create([
            'site_id' => $site->id,
            'type' => 'redis',
            'mode' => 'provision_new',
            'status' => SiteBinding::STATUS_PROVISIONING,
            'name' => $isPrimary ? 'primary' : $connection,
            'target_type' => 'server_cache_service',
            'target_id' => (string) $service->id,
            'injected_env' => [],
            'config' => [
                'engine' => 'redis',
                'connection' => $connection,
                'service' => $name.' · '.__('dedicated'),
                'placement' => 'cache_vm',
                'managed' => false,
                'cache_vm_server_id' => (string) $cacheServer->id,
                'cluster_name' => $name,
                'vm_size' => $size,
                'region' => $placement['region'],
                'use_for_drivers' => filter_var($form['use_for_drivers'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ],
            'last_error' => null,
        ]);

        ProvisionDedicatedRedisVmJob::dispatch(
            (string) $cacheServer->id,
            (string) $site->id,
            (string) $service->id,
            (string) $binding->id,
        );

        return $binding;
    }
}
