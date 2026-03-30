<?php

declare(strict_types=1);

namespace App\Modules\TaskRunner\Policies;

use App\Models\User;
use App\Modules\TaskRunner\Models\Task;
use App\Policies\BasePolicy;

class TaskPolicy extends BasePolicy
{
    protected function initializePolicy(): void
    {
        $this->modelClass = Task::class;
        $this->permissionPrefix = 'task';
        $this->usageLimitType = 'tasks';
    }

    /**
     * Check if user owns the task through server ownership.
     */
    protected function checkModelOwnership(User $user, $model): bool
    {
        // Super admins can access everything
        if ($this->hasSuperAdminRole($user)) {
            return true;
        }

        // Check if task belongs to a server that the user has access to
        if ($model->server) {
            return $user->belongsToTeam($model->server->team);
        }

        return false;
    }
}
