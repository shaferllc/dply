<?php

namespace App\Policies;

use App\Models\Incident;
use App\Models\StatusPage;
use App\Models\User;

class IncidentPolicy
{
    public function view(User $user, Incident $incident): bool
    {
        return $user->can('view', $incident->statusPage);
    }

    public function create(User $user, StatusPage $statusPage): bool
    {
        return $user->can('update', $statusPage);
    }

    public function update(User $user, Incident $incident): bool
    {
        return $user->can('update', $incident->statusPage);
    }
}
