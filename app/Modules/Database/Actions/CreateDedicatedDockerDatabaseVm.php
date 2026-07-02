<?php

declare(strict_types=1);

namespace App\Modules\Database\Actions;

use App\Actions\Servers\StoreServerFromCreateForm;
use App\Enums\ServerProvider;
use App\Livewire\Forms\ServerCreateForm;
use App\Models\ServerDatabase;
use App\Models\Site;
use App\Models\SiteBinding;
use App\Models\User;
use App\Modules\Database\Jobs\ProvisionDedicatedDockerDatabaseVmJob;
use App\Modules\Database\Support\DedicatedDatabaseVm;
use App\Modules\Database\Support\DockerDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Component;
use RuntimeException;

/**
 * Provisions a brand-new Docker host on the customer's connected provider,
 * then starts the database inside a container and attaches it to the site.
 */
class CreateDedicatedDockerDatabaseVm
{
    /**
     * @param  array<string, mixed>  $form  The binding modal's form (engine, name, size).
     */
    public function handle(Component $component, Site $site, array $form): SiteBinding
    {
        $appServer = $site->server;
        if ($appServer === null) {
            throw new RuntimeException(__('This site has no server to anchor a database VM to.'));
        }
        if (! DedicatedDatabaseVm::eligible($appServer)) {
            throw new RuntimeException(__('A dedicated Docker database server needs a connected cloud provider on this server.'));
        }

        $manager = app(\App\Modules\Deploy\Services\SiteBindingManager::class);
        $connection = $manager->resolveInstanceConnectionName($site, 'database', ['connection' => $form['connection'] ?? '']);
        $isPrimary = $connection === '';
        if ($isPrimary) {
            $manager->assertNoOtherPrimaryInstance($site, 'database');
        }

        $engine = strtolower(trim((string) ($form['engine'] ?? 'mysql')));
        if (! in_array($engine, DockerDatabase::supportedEngines(), true)) {
            throw new InvalidArgumentException(__('A dedicated Docker database server supports MySQL, PostgreSQL, or Redis.'));
        }

        $name = trim((string) ($form['name'] ?? ''));
        if ($name === '' || preg_match('/^[a-zA-Z0-9_]+$/', $name) !== 1) {
            throw new InvalidArgumentException(__('Database name must be alphanumeric/underscore.'));
        }

        $size = trim((string) ($form['vm_size'] ?? ''));
        if ($size === '') {
            throw new InvalidArgumentException(__('Choose a size for the database server.'));
        }

        $user = auth()->user();
        if (! $user instanceof User) {
            throw new RuntimeException(__('You must be signed in to provision a database server.'));
        }
        $org = $site->organization ?? $user->currentOrganization();
        if ($org === null) {
            throw new RuntimeException(__('No organization for this site.'));
        }

        $username = Str::limit(Str::slug($name, '_') ?: 'db', 28, '').'_'.Str::lower(Str::random(4));
        $password = Str::password(24, symbols: false);
        $allowedFrom = (string) ($appServer->private_ip_address ?: $appServer->ip_address ?: '');

        $createForm = new ServerCreateForm($component, 'dedicatedDockerDbForm');
        $createForm->mode = 'provider';
        $createForm->type = $appServer->provider->value;
        $createForm->provider_credential_id = (string) $appServer->provider_credential_id;
        $createForm->name = Str::limit(($site->slug ?: 'site').'-docker-db', 60, '');
        $createForm->region = (string) $appServer->region;
        $createForm->size = $size;
        $createForm->server_role = 'docker';
        $createForm->install_profile = 'static_app_host';
        $createForm->cache_service = 'none';
        // Docker role validation requires a webserver id even though roleDocker
        // only installs Docker — nginx is a harmless placeholder here.
        $createForm->webserver = 'nginx';
        $createForm->php_version = 'none';
        $createForm->database = 'none';

        if ($appServer->provider === ServerProvider::DigitalOcean) {
            $vpc = (string) ($appServer->privateNetwork?->provider_id ?? '');
            if ($vpc !== '') {
                $createForm->do_vpc_uuid = $vpc;
            }
        } elseif ($appServer->provider === ServerProvider::Hetzner) {
            $createForm->hetzner_network_id = (string) ($appServer->hetzner_network_id ?? '');
        }

        $dbServer = app(StoreServerFromCreateForm::class)->handle($user, $org, $createForm);

        $database = ServerDatabase::query()->create([
            'server_id' => $dbServer->id,
            'site_id' => $site->id,
            'name' => $name,
            'engine' => $engine,
            'username' => $username,
            'password' => $password,
            'host' => '',
            'remote_access' => true,
            'allowed_from' => $allowedFrom,
            'description' => 'Dedicated Docker database VM for '.$site->slug,
        ]);

        $binding = SiteBinding::query()->create([
            'site_id' => $site->id,
            'type' => 'database',
            'mode' => 'provision_new',
            'status' => SiteBinding::STATUS_PROVISIONING,
            'name' => $isPrimary ? 'primary' : $connection,
            'target_type' => 'server_database',
            'target_id' => (string) $database->id,
            'injected_env' => [],
            'config' => [
                'engine' => $engine,
                'connection' => $connection,
                'database_name' => $name,
                'placement' => 'docker_vm',
                'managed' => false,
                'db_vm_server_id' => (string) $dbServer->id,
            ],
            'last_error' => null,
        ]);

        ProvisionDedicatedDockerDatabaseVmJob::dispatch(
            (string) $dbServer->id,
            (string) $site->id,
            (string) $database->id,
            (string) $binding->id,
        );

        return $binding;
    }
}
