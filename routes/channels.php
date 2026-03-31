<?php

use App\Models\Organization;
use App\Models\Server;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('organization.{organizationId}', function ($user, string $organizationId) {
    $organization = Organization::query()->find($organizationId);
    if ($organization === null || ! $organization->hasMember($user)) {
        return false;
    }

    return ['id' => $organization->id, 'name' => $organization->name];
});

/**
 * Server workspace log snapshots (Reverb). Deployers are excluded so SSH log payloads are not
 * delivered to members who cannot fetch those logs themselves.
 */
Broadcast::channel('server.{serverId}', function ($user, string $serverId) {
    $server = Server::query()->find($serverId);
    if ($server === null || ! $user->can('view', $server)) {
        return false;
    }

    if ($server->organization_id && $server->organization?->userIsDeployer($user)) {
        return false;
    }

    return ['id' => $user->id];
});
