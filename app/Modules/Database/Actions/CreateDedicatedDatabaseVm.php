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
use App\Modules\Database\Jobs\ProvisionDedicatedDatabaseVmJob;
use App\Modules\Database\Support\DedicatedDatabaseVm;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Component;
use RuntimeException;

/**
 * Provisions a brand-new server on the customer's connected provider whose
 * sole job is to host this site's database, then attaches it.
 *
 * Reuses the existing customer-connected create pipeline
 * ({@see StoreServerFromCreateForm}) by driving a {@see ServerCreateForm} as a
 * `database`-role box, co-located in the app server's region + private network.
 * The server-setup installs the engine and creates the initial database; a
 * {@see ProvisionDedicatedDatabaseVmJob} waits for that to finish, then wires
 * the binding's connection vars (over the shared private network).
 *
 * Must run inside a live Livewire component — the create form is a Livewire
 * Form object, and StoreServerFromCreateForm validates as if submitted from
 * the wizard.
 */
class CreateDedicatedDatabaseVm
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
            throw new RuntimeException(__('A dedicated database VM needs a connected cloud provider on this server.'));
        }

        // Connection name (blank = PRIMARY). A PRIMARY dedicated DB never
        // replaces an existing primary — refuse up front (before spinning up a
        // server). A NAMED one (e.g. clickhouse) is added alongside the primary.
        $manager = app(\App\Modules\Deploy\Services\SiteBindingManager::class);
        $connection = $manager->resolveInstanceConnectionName($site, 'database', ['connection' => $form['connection'] ?? '']);
        $isPrimary = $connection === '';
        if ($isPrimary) {
            $manager->assertNoOtherPrimaryInstance($site, 'database');
        }

        $engine = strtolower(trim((string) ($form['engine'] ?? 'mysql')));
        if (! in_array($engine, DedicatedDatabaseVm::supportedEngines(), true)) {
            throw new InvalidArgumentException(__('A dedicated database VM supports MySQL, PostgreSQL, or ClickHouse.'));
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

        // Credentials are generated here so the ServerDatabase row, the server
        // setup (which creates the initial DB+user), and the injected env all
        // agree from the start.
        $username = Str::limit(Str::slug($name, '_') ?: 'db', 28, '').'_'.Str::lower(Str::random(4));
        $password = Str::password(24);
        $allowedFrom = (string) ($appServer->private_ip_address ?: $appServer->ip_address ?: '');

        $createForm = new ServerCreateForm($component, 'dedicatedDbForm');
        $createForm->mode = 'provider';
        $createForm->type = $appServer->provider->value;
        $createForm->provider_credential_id = (string) $appServer->provider_credential_id;
        $createForm->name = Str::limit(($site->slug ?: 'site').'-db', 60, '');
        $createForm->region = (string) $appServer->region;
        $createForm->size = $size;
        $createForm->server_role = 'database';
        $createForm->install_profile = 'database_node';
        // A pure database node has no app stack. The create-form validation
        // (ServerProvisionPreferenceRules, filtered by server_role) only allows
        // 'none' for these on a database role, so the app defaults
        // (redis / nginx / 8.3) would fail validation and abort provisioning.
        $createForm->cache_service = 'none';
        $createForm->webserver = 'none';
        $createForm->php_version = 'none';
        $createForm->database = DedicatedDatabaseVm::engineFormat($engine);
        $createForm->database_initial_name = $name;
        $createForm->database_username = $username;
        $createForm->database_password = $password;
        $createForm->database_remote_access = true;
        $createForm->database_allowed_from = $allowedFrom;

        // Co-locate on the app server's private network so the app dials the DB
        // over a private IP. VPC plumbing is provider-specific.
        if ($appServer->provider === ServerProvider::DigitalOcean) {
            $vpc = (string) ($appServer->privateNetwork?->provider_id ?? '');
            if ($vpc !== '') {
                $createForm->do_vpc_uuid = $vpc;
            }
        } elseif ($appServer->provider === ServerProvider::Hetzner) {
            $createForm->hetzner_network_id = (string) ($appServer->hetzner_network_id ?? '');
        }

        $dbServer = app(StoreServerFromCreateForm::class)->handle($user, $org, $createForm);

        // Record the database now (creds known); host is filled in once the box
        // has a private IP. site_id marks single-owner ownership by this site.
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
            'description' => 'Dedicated database VM for '.$site->slug,
        ]);

        // Primary (uniqueness asserted up front) keeps the bare DB_* keys; a
        // named instance (e.g. clickhouse) is keyed by its slug and injects
        // DB_<SLUG>_* once the box is ready (see wireServerDatabaseBinding).
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
                'placement' => 'dedicated_vm',
                'managed' => false,
                'db_vm_server_id' => (string) $dbServer->id,
            ],
            'last_error' => null,
        ]);

        ProvisionDedicatedDatabaseVmJob::dispatch(
            (string) $dbServer->id,
            (string) $site->id,
            (string) $database->id,
            (string) $binding->id,
        );

        return $binding;
    }
}
