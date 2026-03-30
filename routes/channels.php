<?php

use App\Models\Organization;
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
