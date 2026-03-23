<?php

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

if (! function_exists('audit_log')) {
    /**
     * Log an action to the organization audit log.
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
