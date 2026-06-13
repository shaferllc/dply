<?php

declare(strict_types=1);

namespace App\Actions\Servers\Concerns;

use App\Actions\Servers\BuildServerProvisionMeta;
use App\Jobs\RunSetupScriptJob;
use App\Livewire\Forms\ServerCreateForm;
use App\Models\Server;
use App\Models\User;
use App\Notifications\RedisServerProvisioningStartedNotification;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait BuildsServerStoreMeta
{


    /**
     * Heads-up email the moment a dedicated redis server begins provisioning.
     * Single chokepoint for every provider; the "ready" email with the reveal
     * link fires later from RunSetupScriptJob. Idempotent via a meta flag.
     */
    private function notifyRedisProvisioningStarted(Server $server, User $user): void
    {
        if (! $server->isRedisServer()) {
            return;
        }

        if (! filled($user->email)) {
            return;
        }

        if (filled(data_get($server->meta, 'redis_starting_email_sent_at'))) {
            return;
        }

        $user->notify(new RedisServerProvisioningStartedNotification($server));

        $meta = $server->meta ?? [];
        $meta['redis_starting_email_sent_at'] = now()->toIso8601String();
        $server->update(['meta' => $meta]);
    }

    /**
     * @param  list<string>  $scriptKeys
     */
    private function meta(ServerCreateForm $form): array
    {
        $meta = BuildServerProvisionMeta::run(
            $form->install_profile,
            $form->server_role,
            $form->cache_service,
            $form->webserver,
            $form->php_version,
            $form->database,
            [
                'ruby' => $form->ruby_version,
                'node' => $form->node_version,
                'python' => $form->python_version,
                'go' => $form->go_version,
            ],
            cacheRemoteAccess: $form->cache_remote_access,
            cacheAllowedFrom: $form->cache_allowed_from,
            cacheRequirePassword: $form->cache_require_password,
            cachePassword: $form->cache_password !== '' ? $form->cache_password : null,
            databaseRemoteAccess: $form->database_remote_access,
            databaseAllowedFrom: $form->database_allowed_from,
            databaseInitialName: $form->database_initial_name,
            databaseUsername: $form->database_username,
            databasePassword: $form->database_password !== '' ? $form->database_password : null,
        );

        // Provider-mode Docker hosts: tag the meta so Server::hostKind() returns
        // HOST_KIND_DOCKER. Custom-mode does this inline in its own branch.
        if ($form->mode === 'provider' && $form->provider_host_kind === 'docker') {
            $meta['host_kind'] = Server::HOST_KIND_DOCKER;
        }

        // Chosen OS image (provider VM hosts only). Resolved to a provider-native
        // slug at provision time; absent means "use the provider default".
        if ($form->os_image !== '') {
            $meta['os_image'] = $form->os_image;
        }

        return $meta;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function setupScriptState(string $setupScriptKey): array
    {
        $key = ! empty(trim($setupScriptKey)) ? $setupScriptKey : null;
        $status = $key ? Server::SETUP_STATUS_PENDING : null;

        return [$key, $status];
    }
}
