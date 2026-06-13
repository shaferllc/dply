<?php

declare(strict_types=1);

namespace App\Actions\Servers;

use App\Actions\Concerns\AsObject;
use App\Actions\Servers\Concerns\BuildsServerStoreMeta;
use App\Actions\Servers\Concerns\StoresAwsServers;
use App\Actions\Servers\Concerns\StoresDigitalOceanServers;
use App\Actions\Servers\Concerns\StoresOtherProviderServers;
use App\Enums\ServerProvider;
use App\Jobs\PollDoksClusterStatusJob;
use App\Jobs\PollEksClusterStatusJob;
use App\Jobs\ProvisionAwsEc2ServerJob;
use App\Jobs\ProvisionAzureServerJob;
use App\Jobs\ProvisionDigitalOceanDropletJob;
use App\Jobs\ProvisionHetznerServerJob;
use App\Jobs\ProvisionLinodeServerJob;
use App\Jobs\ProvisionOracleServerJob;
use App\Jobs\ProvisionUpCloudServerJob;
use App\Jobs\ProvisionVultrServerJob;
use App\Jobs\RunSetupScriptJob;
use App\Jobs\WaitForServerSshReadyJob;
use App\Livewire\Forms\ServerCreateForm;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\User;
use App\Notifications\RedisServerProvisioningStartedNotification;
use App\Services\AwsEksService;
use App\Services\DigitalOceanService;
use App\Services\HetznerService;
use App\Support\ServerProviderGate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Persists a server from the create wizard (cloud API providers or custom SSH).
 */
final class StoreServerFromCreateForm
{
    use AsObject;
    use BuildsServerStoreMeta;
    use StoresAwsServers;
    use StoresDigitalOceanServers;
    use StoresOtherProviderServers;

    public function handle(User $user, Organization $org, ServerCreateForm $form): Server
    {
        if (! ServerProviderGate::enabled($form->type)) {
            throw ValidationException::withMessages([
                'form.type' => __('This server provider is not available yet.'),
            ]);
        }

        $scriptKeys = array_keys(config('setup_scripts.scripts', []));

        if ($form->type === 'custom') {
            $hasLinkedCredential = GetProviderCredentialsForServerType::run($org, $form->type)->isNotEmpty();
            $installProfileIds = collect(config('server_provision_options.install_profiles', []))->pluck('id')->filter()->values()->all();
            Validator::make(
                [
                    'install_profile' => $form->install_profile,
                    'server_role' => $form->server_role,
                    'cache_service' => $form->cache_service,
                    'webserver' => $form->webserver,
                    'php_version' => $form->php_version,
                    'database' => $form->database,
                    'setup_script_key' => $form->setup_script_key,
                ],
                array_merge(
                    [
                        'install_profile' => ['required', 'string', Rule::in($installProfileIds)],
                        'setup_script_key' => ['nullable', 'string', Rule::in(array_merge([''], $scriptKeys))],
                    ],
                    ServerProvisionPreferenceRules::rules('custom', $hasLinkedCredential, $form->server_role)
                )
            )->validate();
        }

        if (! in_array($form->type, ['custom', 'digitalocean_functions', 'digitalocean_kubernetes', 'aws_kubernetes', 'aws_lambda'], true)) {
            $hasLinkedCredential = GetProviderCredentialsForServerType::run($org, $form->type)->isNotEmpty();
            Validator::make(
                [
                    'server_role' => $form->server_role,
                    'cache_service' => $form->cache_service,
                    'webserver' => $form->webserver,
                    'php_version' => $form->php_version,
                    'database' => $form->database,
                ],
                ServerProvisionPreferenceRules::rules($form->type, $hasLinkedCredential, $form->server_role)
            )->validate();
        }

        $server = match ($form->type) {
            'digitalocean' => $this->storeDigitalOcean($user, $org, $form, $scriptKeys),
            'digitalocean_functions' => $this->storeDigitalOceanFunctions($user, $org, $form),
            'digitalocean_kubernetes' => $this->storeDigitalOceanKubernetes($user, $org, $form),
            'aws_kubernetes' => $this->storeAwsKubernetes($user, $org, $form),
            'aws_lambda' => $this->storeAwsLambda($user, $org, $form),
            'hetzner' => $this->storeHetzner($user, $org, $form, $scriptKeys),
            'linode' => $this->storeLinode($user, $org, $form, $scriptKeys),
            'vultr' => $this->storeVultr($user, $org, $form, $scriptKeys),
            'upcloud' => $this->storeUpcloud($user, $org, $form, $scriptKeys),
            'aws' => $this->storeAws($user, $org, $form, $scriptKeys),
            'azure' => $this->storeAzure($user, $org, $form, $scriptKeys),
            'oracle' => $this->storeOracle($user, $org, $form, $scriptKeys),
            'custom' => $this->storeCustom($user, $org, $form),
            default => throw ValidationException::withMessages(['form.type' => __('Invalid server type.')]),
        };

        $this->notifyRedisProvisioningStarted($server, $user);

        return $server;
    }


}
