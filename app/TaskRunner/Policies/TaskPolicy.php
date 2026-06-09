<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Policies;

use App\Models\User;
use App\Modules\TaskRunner\Models\Task;

/**
 * Tasks are owned through the server they run against — access mirrors the
 * server's own policy. (This module originally shipped extending a generic
 * `App\Policies\BasePolicy` that does not exist in this app; it is rewritten
 * here to follow the host app's server-scoped policy conventions.)
 */
class TaskPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->currentOrganization() !== null;
    }

    public function view(User $user, Task $task): bool
    {
        return $task->server !== null && $user->can('view', $task->server);
    }

    public function create(User $user): bool
    {
        $org = $user->currentOrganization();

        return $org !== null && ! $org->userIsDeployer($user);
    }

    public function update(User $user, Task $task): bool
    {
        return $task->server !== null && $user->can('update', $task->server);
    }

    public function delete(User $user, Task $task): bool
    {
        return $task->server !== null && $user->can('update', $task->server);
    }
}
