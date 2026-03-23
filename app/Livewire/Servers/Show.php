<?php

namespace App\Livewire\Servers;

use App\Enums\ServerProvider;
use App\Jobs\CheckServerHealthJob;
use App\Models\Server;
use App\Models\ServerAuthorizedKey;
use App\Models\ServerCronJob;
use App\Models\ServerDatabase;
use App\Models\ServerFirewallRule;
use App\Models\ServerRecipe;
use App\Models\SupervisorProgram;
use App\Services\AwsEc2Service;
use App\Services\DigitalOceanService;
use App\Services\EquinixMetalService;
use App\Services\FlyIoService;
use App\Services\HetznerService;
use App\Services\LinodeService;
use App\Services\ScalewayService;
use App\Services\Servers\ServerAuthorizedKeysSynchronizer;
use App\Services\Servers\ServerCronSynchronizer;
use App\Services\Servers\ServerDatabaseProvisioner;
use App\Services\Servers\ServerFirewallProvisioner;
use App\Services\Servers\SupervisorProvisioner;
use App\Services\SshConnection;
use App\Services\UpCloudService;
use App\Services\VultrService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Show extends Component
{
    public Server $server;

    public string $command = '';

    public string $deploy_command = '';

    public string $health_check_url = '';

    public ?string $command_output = null;

    public ?string $command_error = null;

    public ?string $flash_success = null;

    public ?string $flash_error = null;

    public string $new_db_name = '';

    public string $new_db_engine = 'mysql';

    public string $new_db_username = '';

    public string $new_db_password = '';

    public string $new_db_host = '127.0.0.1';

    public string $new_cron_expression = '* * * * *';

    public string $new_cron_command = '';

    public string $new_cron_user = 'root';

    public string $new_sv_slug = '';

    public string $new_sv_type = 'queue';

    public string $new_sv_command = 'php artisan queue:work --sleep=3 --tries=3';

    public string $new_sv_directory = '';

    public string $new_sv_user = 'www-data';

    public int $new_sv_numprocs = 1;

    public int $new_fw_port = 80;

    public string $new_fw_protocol = 'tcp';

    public string $new_auth_name = '';

    public string $new_auth_key = '';

    public string $new_recipe_name = '';

    public string $new_recipe_script = '';

    public function mount(Server $server): void
    {
        $this->authorize('view', $server);
        $this->server = $server;
        $this->deploy_command = $server->deploy_command ?? '';
        $this->health_check_url = (string) ($server->meta['health_check_url'] ?? '');
    }

    public function createDatabase(ServerDatabaseProvisioner $provisioner): void
    {
        $this->authorize('update', $this->server);
        $this->validate([
            'new_db_name' => 'required|string|max:64|regex:/^[a-zA-Z0-9_]+$/',
            'new_db_engine' => 'required|in:mysql,postgres',
            'new_db_username' => 'required|string|max:64|regex:/^[a-zA-Z0-9_]+$/',
            'new_db_password' => 'required|string|max:200',
            'new_db_host' => 'required|string|max:255',
        ]);

        $this->flash_success = null;
        $this->flash_error = null;

        try {
            $db = ServerDatabase::query()->create([
                'server_id' => $this->server->id,
                'name' => $this->new_db_name,
                'engine' => $this->new_db_engine,
                'username' => $this->new_db_username,
                'password' => $this->new_db_password,
                'host' => $this->new_db_host,
            ]);
            $out = $provisioner->createOnServer($db);
            $this->flash_success = 'Database record created and provision attempted on server. SSH output (if any): '.Str::limit($out, 500);
            $this->new_db_name = '';
            $this->new_db_username = '';
            $this->new_db_password = '';
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
        }
    }

    public function deleteDatabase(int $id): void
    {
        $this->authorize('update', $this->server);
        $db = ServerDatabase::query()->where('server_id', $this->server->id)->findOrFail($id);
        $db->delete();
        $this->flash_success = 'Database entry removed from Dply (remote DB not dropped).';
        $this->flash_error = null;
    }

    public function addCronJob(): void
    {
        $this->authorize('update', $this->server);
        $this->validate([
            'new_cron_expression' => 'required|string|max:64',
            'new_cron_command' => 'required|string|max:2000',
            'new_cron_user' => 'required|string|max:64',
        ]);
        ServerCronJob::query()->create([
            'server_id' => $this->server->id,
            'cron_expression' => trim($this->new_cron_expression),
            'command' => trim($this->new_cron_command),
            'user' => trim($this->new_cron_user),
            'is_synced' => false,
        ]);
        $this->new_cron_command = '';
        $this->flash_success = 'Cron job added. Click “Sync crontab” to install the Dply-managed block on the server.';
        $this->flash_error = null;
    }

    public function deleteCronJob(int $id): void
    {
        $this->authorize('update', $this->server);
        ServerCronJob::query()->where('server_id', $this->server->id)->findOrFail($id)->delete();
        $this->flash_success = 'Cron entry removed. Sync crontab again to update the server.';
        $this->flash_error = null;
    }

    public function syncCronJobs(ServerCronSynchronizer $synchronizer): void
    {
        $this->authorize('update', $this->server);
        $this->flash_success = null;
        $this->flash_error = null;
        try {
            $this->server->refresh();
            $out = $synchronizer->sync($this->server);
            $this->flash_success = 'Crontab sync finished. Output: '.Str::limit(trim($out), 800);
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
        }
    }

    public function addSupervisorProgram(): void
    {
        $this->authorize('update', $this->server);
        $this->validate([
            'new_sv_slug' => 'required|string|max:64|regex:/^[a-z0-9\-]+$/',
            'new_sv_type' => 'required|string|max:32',
            'new_sv_command' => 'required|string|max:2000',
            'new_sv_directory' => 'required|string|max:512',
            'new_sv_user' => 'required|string|max:64',
            'new_sv_numprocs' => 'required|integer|min:1|max:32',
        ]);
        SupervisorProgram::query()->create([
            'server_id' => $this->server->id,
            'site_id' => null,
            'slug' => $this->new_sv_slug,
            'program_type' => $this->new_sv_type,
            'command' => $this->new_sv_command,
            'directory' => $this->new_sv_directory,
            'user' => $this->new_sv_user,
            'numprocs' => $this->new_sv_numprocs,
            'is_active' => true,
        ]);
        $this->new_sv_slug = '';
        $this->flash_success = 'Supervisor program saved. Click “Sync Supervisor”.';
        $this->flash_error = null;
    }

    public function deleteSupervisorProgram(int $id, SupervisorProvisioner $provisioner): void
    {
        $this->authorize('update', $this->server);
        $prog = SupervisorProgram::query()->where('server_id', $this->server->id)->whereKey($id)->first();
        if ($prog) {
            $provisioner->deleteConfigFile($this->server, $prog->id);
            $prog->delete();
        }
        $this->flash_success = 'Removed. Sync Supervisor to reload on the server.';
        $this->flash_error = null;
    }

    public function syncSupervisor(SupervisorProvisioner $provisioner): void
    {
        $this->authorize('update', $this->server);
        $this->flash_success = null;
        $this->flash_error = null;
        try {
            $this->server->refresh();
            $out = $provisioner->sync($this->server);
            $this->flash_success = 'Supervisor: '.Str::limit(trim($out), 1200);
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
        }
    }

    public function addFirewallRule(): void
    {
        $this->authorize('update', $this->server);
        $this->validate([
            'new_fw_port' => 'required|integer|min:1|max:65535',
            'new_fw_protocol' => 'required|in:tcp,udp',
        ]);
        ServerFirewallRule::query()->create([
            'server_id' => $this->server->id,
            'port' => $this->new_fw_port,
            'protocol' => $this->new_fw_protocol,
            'action' => 'allow',
            'sort_order' => (int) ($this->server->firewallRules()->max('sort_order') ?? 0) + 1,
        ]);
        $this->flash_success = 'Rule saved. Click “Apply UFW rules”. Ensure SSH is allowed before enabling UFW.';
        $this->flash_error = null;
    }

    public function deleteFirewallRule(int $id): void
    {
        $this->authorize('update', $this->server);
        ServerFirewallRule::query()->where('server_id', $this->server->id)->whereKey($id)->delete();
        $this->flash_success = 'Rule removed.';
        $this->flash_error = null;
    }

    public function applyFirewall(ServerFirewallProvisioner $firewall): void
    {
        $this->authorize('update', $this->server);
        $this->flash_success = null;
        $this->flash_error = null;
        try {
            $this->server->refresh();
            $out = $firewall->apply($this->server);
            $this->flash_success = 'UFW: '.Str::limit(trim($out), 1200);
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
        }
    }

    public function addAuthorizedKey(): void
    {
        $this->authorize('update', $this->server);
        $this->validate([
            'new_auth_name' => 'required|string|max:120',
            'new_auth_key' => 'required|string|max:4000',
        ]);
        ServerAuthorizedKey::query()->create([
            'server_id' => $this->server->id,
            'name' => $this->new_auth_name,
            'public_key' => trim($this->new_auth_key),
        ]);
        $this->new_auth_name = '';
        $this->new_auth_key = '';
        $this->flash_success = 'Key stored. Click “Sync authorized_keys”.';
        $this->flash_error = null;
    }

    public function deleteAuthorizedKey(int $id): void
    {
        $this->authorize('update', $this->server);
        ServerAuthorizedKey::query()->where('server_id', $this->server->id)->whereKey($id)->delete();
        $this->flash_success = 'Key removed. Sync again to update server.';
        $this->flash_error = null;
    }

    public function syncAuthorizedKeys(ServerAuthorizedKeysSynchronizer $sync): void
    {
        $this->authorize('update', $this->server);
        $this->flash_success = null;
        $this->flash_error = null;
        try {
            $this->server->refresh();
            $out = $sync->sync($this->server);
            $this->flash_success = 'authorized_keys updated. '.$out;
        } catch (\Throwable $e) {
            $this->flash_error = $e->getMessage();
        }
    }

    public function addRecipe(): void
    {
        $this->authorize('update', $this->server);
        $this->validate([
            'new_recipe_name' => 'required|string|max:160',
            'new_recipe_script' => 'required|string|max:32000',
        ]);
        ServerRecipe::query()->create([
            'server_id' => $this->server->id,
            'user_id' => auth()->id(),
            'name' => $this->new_recipe_name,
            'script' => $this->new_recipe_script,
        ]);
        $this->new_recipe_name = '';
        $this->new_recipe_script = '';
        $this->flash_success = 'Recipe saved.';
        $this->flash_error = null;
    }

    public function deleteRecipe(int $id): void
    {
        $this->authorize('update', $this->server);
        ServerRecipe::query()->where('server_id', $this->server->id)->whereKey($id)->delete();
        $this->flash_success = 'Recipe removed.';
        $this->flash_error = null;
    }

    public function runRecipe(int $id): void
    {
        $this->authorize('update', $this->server);
        $recipe = ServerRecipe::query()->where('server_id', $this->server->id)->findOrFail($id);
        $this->command_output = null;
        $this->command_error = null;
        try {
            $ssh = new SshConnection($this->server);
            $b64 = base64_encode($recipe->script);
            $this->command_output = $ssh->exec(
                'echo '.escapeshellarg($b64).' | base64 -d | /usr/bin/env bash 2>&1',
                900
            );
            $this->flash_success = 'Recipe ran. See command output below if shown.';
        } catch (\Throwable $e) {
            $this->command_error = $e->getMessage();
        }
    }

    public function runCommand(): void
    {
        $this->authorize('view', $this->server);
        $this->validate(['command' => 'required|string|max:1000']);
        $this->command_output = null;
        $this->command_error = null;

        try {
            $ssh = new SshConnection($this->server);
            $this->command_output = $ssh->exec($this->command);
            $this->command = '';
        } catch (\Throwable $e) {
            $this->command_error = $e->getMessage();
        }
    }

    public function deploy(): void
    {
        $this->authorize('view', $this->server);
        $cmd = $this->server->deploy_command;
        if (empty(trim((string) $cmd))) {
            $this->flash_error = 'Set a deploy command first. Use "Edit deploy command" below.';

            return;
        }
        $this->command_output = null;
        $this->command_error = null;

        try {
            $ssh = new SshConnection($this->server);
            $this->command_output = $ssh->exec($cmd);
        } catch (\Throwable $e) {
            $this->command_error = $e->getMessage();
        }
    }

    public function updateDeployCommand(): void
    {
        $this->authorize('update', $this->server);
        $this->validate(['deploy_command' => 'nullable|string|max:2000']);
        $this->server->update(['deploy_command' => trim($this->deploy_command) ?: null]);
        $this->flash_success = 'Deploy command updated.';
    }

    public function applyDeployTemplate(string $key): void
    {
        $this->authorize('update', $this->server);
        $templates = config('deploy_templates.templates', []);
        $template = $templates[$key] ?? null;
        if ($template && ! empty($template['command'])) {
            $this->deploy_command = $template['command'];
            $this->server->update(['deploy_command' => $template['command']]);
            $this->flash_success = 'Deploy template applied. Edit below if needed, then save.';
        }
    }

    public function checkHealth(): void
    {
        $this->authorize('view', $this->server);
        if ($this->server->status === Server::STATUS_READY && ! empty($this->server->ip_address)) {
            CheckServerHealthJob::dispatch($this->server);
        }
        $this->flash_success = 'Health check has been queued. Status will update shortly.';
    }

    public function saveHealthCheckUrl(): void
    {
        $this->authorize('update', $this->server);
        $this->validate(['health_check_url' => 'nullable|string|url|max:500']);
        $meta = $this->server->meta ?? [];
        $meta['health_check_url'] = trim($this->health_check_url) ?: null;
        if ($meta['health_check_url'] === null) {
            unset($meta['health_check_url']);
        }
        $this->server->update(['meta' => $meta]);
        $this->flash_success = 'Health check URL updated.';
    }

    public function destroy(): mixed
    {
        $this->authorize('delete', $this->server);

        $org = $this->server->organization;
        if ($org) {
            audit_log($org, auth()->user(), 'server.deleted', $this->server, ['name' => $this->server->name], null);
        }

        if ($this->server->provider === ServerProvider::DigitalOcean && ! empty($this->server->provider_id)) {
            $credential = $this->server->providerCredential;
            if ($credential) {
                try {
                    $do = new DigitalOceanService($credential);
                    $do->destroyDroplet((int) $this->server->provider_id);
                } catch (\Throwable $e) {
                    Log::warning('Failed to destroy DigitalOcean droplet on server delete.', [
                        'server_id' => $this->server->id,
                        'provider_id' => $this->server->provider_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($this->server->provider === ServerProvider::Hetzner && ! empty($this->server->provider_id)) {
            $credential = $this->server->providerCredential;
            if ($credential) {
                try {
                    $hetzner = new HetznerService($credential);
                    $hetzner->destroyInstance((int) $this->server->provider_id);
                } catch (\Throwable $e) {
                    Log::warning('Failed to destroy Hetzner instance on server delete.', [
                        'server_id' => $this->server->id,
                        'provider_id' => $this->server->provider_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if (in_array($this->server->provider, [ServerProvider::Linode, ServerProvider::Akamai], true) && ! empty($this->server->provider_id)) {
            $credential = $this->server->providerCredential;
            if ($credential) {
                try {
                    $linode = new LinodeService($credential);
                    $linode->destroyInstance((int) $this->server->provider_id);
                } catch (\Throwable $e) {
                    Log::warning('Failed to destroy Linode/Akamai instance on server delete.', [
                        'server_id' => $this->server->id,
                        'provider_id' => $this->server->provider_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($this->server->provider === ServerProvider::Vultr && ! empty($this->server->provider_id)) {
            $credential = $this->server->providerCredential;
            if ($credential) {
                try {
                    $vultr = new VultrService($credential);
                    $vultr->destroyInstance($this->server->provider_id);
                } catch (\Throwable $e) {
                    Log::warning('Failed to destroy Vultr instance on server delete.', [
                        'server_id' => $this->server->id,
                        'provider_id' => $this->server->provider_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($this->server->provider === ServerProvider::Scaleway && ! empty($this->server->provider_id)) {
            $credential = $this->server->providerCredential;
            if ($credential) {
                try {
                    $scw = new ScalewayService($credential);
                    $scw->destroyServer($this->server->region, $this->server->provider_id);
                } catch (\Throwable $e) {
                    Log::warning('Failed to destroy Scaleway instance on server delete.', [
                        'server_id' => $this->server->id,
                        'provider_id' => $this->server->provider_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($this->server->provider === ServerProvider::UpCloud && ! empty($this->server->provider_id)) {
            $credential = $this->server->providerCredential;
            if ($credential) {
                try {
                    $upcloud = new UpCloudService($credential);
                    $upcloud->destroyServer($this->server->provider_id);
                } catch (\Throwable $e) {
                    Log::warning('Failed to destroy UpCloud server on server delete.', [
                        'server_id' => $this->server->id,
                        'provider_id' => $this->server->provider_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($this->server->provider === ServerProvider::EquinixMetal && ! empty($this->server->provider_id)) {
            $credential = $this->server->providerCredential;
            if ($credential) {
                try {
                    $metal = new EquinixMetalService($credential);
                    $metal->destroyDevice($this->server->provider_id);
                } catch (\Throwable $e) {
                    Log::warning('Failed to destroy Equinix Metal device on server delete.', [
                        'server_id' => $this->server->id,
                        'provider_id' => $this->server->provider_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($this->server->provider === ServerProvider::FlyIo && ! empty($this->server->provider_id)) {
            $credential = $this->server->providerCredential;
            $appName = $this->server->meta['app_name'] ?? null;
            if ($credential && $appName) {
                try {
                    $fly = new FlyIoService($credential);
                    $fly->deleteMachine($appName, $this->server->provider_id);
                    $fly->deleteApp($appName);
                } catch (\Throwable $e) {
                    Log::warning('Failed to destroy Fly.io machine/app on server delete.', [
                        'server_id' => $this->server->id,
                        'provider_id' => $this->server->provider_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($this->server->provider === ServerProvider::Aws && ! empty($this->server->provider_id)) {
            $credential = $this->server->providerCredential;
            if ($credential) {
                try {
                    $aws = new AwsEc2Service($credential, $this->server->region);
                    $aws->terminateInstances($this->server->provider_id);
                    $keyName = $this->server->meta['key_name'] ?? null;
                    if ($keyName) {
                        try {
                            $aws->deleteKeyPair($keyName);
                        } catch (\Throwable) {
                            //
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning('Failed to destroy AWS EC2 instance on server delete.', [
                        'server_id' => $this->server->id,
                        'provider_id' => $this->server->provider_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->server->delete();

        return $this->redirect(route('servers.index'), navigate: true);
    }

    public function render(): View
    {
        $this->server->refresh();
        $this->server->load([
            'sites.domains',
            'serverDatabases',
            'cronJobs',
            'supervisorPrograms',
            'firewallRules',
            'authorizedKeys',
            'recipes',
        ]);

        return view('livewire.servers.show');
    }
}
