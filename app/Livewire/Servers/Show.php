<?php

namespace App\Livewire\Servers;

use App\Jobs\CheckServerHealthJob;
use App\Enums\ServerProvider;
use App\Models\Server;
use App\Models\ServerCronJob;
use App\Models\ServerDatabase;
use App\Services\DigitalOceanService;
use App\Services\HetznerService;
use App\Services\AwsEc2Service;
use App\Services\EquinixMetalService;
use App\Services\FlyIoService;
use App\Services\LinodeService;
use App\Services\ScalewayService;
use App\Services\UpCloudService;
use App\Services\VultrService;
use App\Services\Servers\ServerCronSynchronizer;
use App\Services\Servers\ServerDatabaseProvisioner;
use App\Services\SshConnection;
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
        $this->server->load(['sites.domains', 'serverDatabases', 'cronJobs']);

        return view('livewire.servers.show');
    }
}
