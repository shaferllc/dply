<?php

namespace App\Services\Servers;

use App\Models\OrganizationSshKey;
use App\Models\Server;
use App\Models\ServerAuthorizedKey;
use App\Models\ServerSshKeyAuditEvent;
use App\Models\TeamSshKey;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Request;

class OrganizationTeamSshKeyServerDeployer
{
    public function __construct(
        protected ServerAuthorizedKeysSynchronizer $synchronizer,
        protected ServerAuthorizedKeysAuditLogger $auditLogger,
    ) {}

    public function deployOrganizationKey(User $actor, OrganizationSshKey $key, Server $server, string $targetLinuxUserStored): array
    {
        if ($key->organization_id !== $server->organization_id) {
            return ['ok' => false, 'message' => __('That key does not belong to this organization.')];
        }

        if (! Gate::forUser($actor)->allows('update', $server)) {
            return ['ok' => false, 'message' => __('You cannot update this server.')];
        }

        if (! $server->isReady() || empty($server->ssh_private_key)) {
            return ['ok' => false, 'message' => __('Server must be ready with an SSH key.')];
        }

        ServerAuthorizedKey::query()->updateOrCreate(
            [
                'server_id' => $server->id,
                'managed_key_type' => OrganizationSshKey::class,
                'managed_key_id' => $key->id,
                'target_linux_user' => $targetLinuxUserStored,
            ],
            [
                'name' => $key->name,
                'public_key' => trim($key->public_key),
            ]
        );

        $this->auditLogger->record(
            $server,
            ServerSshKeyAuditEvent::EVENT_ORG_KEY_DEPLOYED,
            ['organization_ssh_key_id' => $key->id, 'name' => $key->name],
            $actor,
            Request::ip()
        );

        $this->synchronizer->sync($server->fresh(['authorizedKeys']), $actor, Request::ip());

        return ['ok' => true, 'message' => __('Organization key linked and synced.')];
    }

    public function deployTeamKey(User $actor, TeamSshKey $key, Server $server, string $targetLinuxUserStored): array
    {
        if ((string) $key->team_id !== (string) $server->team_id) {
            return ['ok' => false, 'message' => __('That key does not belong to this server’s team.')];
        }

        if (! Gate::forUser($actor)->allows('update', $server)) {
            return ['ok' => false, 'message' => __('You cannot update this server.')];
        }

        if (! $server->isReady() || empty($server->ssh_private_key)) {
            return ['ok' => false, 'message' => __('Server must be ready with an SSH key.')];
        }

        ServerAuthorizedKey::query()->updateOrCreate(
            [
                'server_id' => $server->id,
                'managed_key_type' => TeamSshKey::class,
                'managed_key_id' => $key->id,
                'target_linux_user' => $targetLinuxUserStored,
            ],
            [
                'name' => $key->name,
                'public_key' => trim($key->public_key),
            ]
        );

        $this->auditLogger->record(
            $server,
            ServerSshKeyAuditEvent::EVENT_TEAM_KEY_DEPLOYED,
            ['team_ssh_key_id' => $key->id, 'name' => $key->name],
            $actor,
            Request::ip()
        );

        $this->synchronizer->sync($server->fresh(['authorizedKeys']), $actor, Request::ip());

        return ['ok' => true, 'message' => __('Team key linked and synced.')];
    }
}
