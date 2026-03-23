<?php

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

if (! function_exists('audit_log')) {
    /**
     * Log an action to the organization audit log.
     *
     * @param  \App\Models\Organization  $organization
     * @param  \App\Models\User|null  $user
     * @param  string  $action
     * @param  \Illuminate\Database\Eloquent\Model|null  $subject
     * @param  array|null  $oldValues
     * @param  array|null  $newValues
     * @return \App\Models\AuditLog
     */
    function audit_log(
        Organization $organization,
        ?User $user,
        string $action,
        ?Model $subject = null,
        ?array $oldValues = null,
        ?array $newValues = null
    ): AuditLog {
        return AuditLog::log($organization, $user, $action, $subject, $oldValues, $newValues);
    }
}
