<?php

namespace App\Services\Servers;

use App\Models\Organization;

/**
 * Legacy hook — dual approval is handled by FirewallDualApprovalService (web UI only).
 */
class FirewallApprovalGate
{
    public function blockReason(Organization $organization): ?string
    {
        return null;
    }
}
