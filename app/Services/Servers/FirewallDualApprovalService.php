<?php

namespace App\Services\Servers;

use App\Models\Organization;
use App\Models\Server;
use App\Models\User;

/**
 * When merged org firewall settings enable require_second_approval (config default +
 * organizations.firewall_settings JSON), web Apply requires a second org member to
 * confirm (different user) before UFW runs.
 */
class FirewallDualApprovalService
{
    public function __construct(
        private FirewallRuleStateHasher $hasher,
    ) {}

    /**
     * @return array{proceed: bool, message: ?string, tone: 'none'|'success'|'error'}
     */
    public function evaluateWebApply(Server $server, User $user): array
    {
        $org = $server->organization;
        if (! $org instanceof Organization) {
            return ['proceed' => true, 'message' => null, 'tone' => 'none'];
        }

        $settings = $org->mergedFirewallSettings();
        if (empty($settings['require_second_approval'])) {
            return ['proceed' => true, 'message' => null, 'tone' => 'none'];
        }

        $server->refresh();
        $hash = $this->hasher->hashServerRules($server)['hash'];
        $meta = $server->meta ?? [];
        $pending = $meta['firewall_approval_pending'] ?? null;

        if (! is_array($pending)) {
            $meta['firewall_approval_pending'] = [
                'requested_by' => $user->id,
                'requested_at' => now()->toIso8601String(),
                'rules_hash' => $hash,
            ];
            $server->update(['meta' => $meta]);
            $server->refresh();

            return [
                'proceed' => false,
                'message' => __('Confirmation required: another organization member must click “Apply firewall rules” to push these rules to the server.'),
                'tone' => 'success',
            ];
        }

        if (($pending['rules_hash'] ?? '') !== $hash) {
            $meta['firewall_approval_pending'] = [
                'requested_by' => $user->id,
                'requested_at' => now()->toIso8601String(),
                'rules_hash' => $hash,
            ];
            $server->update(['meta' => $meta]);
            $server->refresh();

            return [
                'proceed' => false,
                'message' => __('Rules changed since the last request — confirmation reset. Another member must confirm the updated rule set.'),
                'tone' => 'success',
            ];
        }

        $requesterId = $pending['requested_by'] ?? null;
        if ((string) $requesterId === (string) $user->id) {
            return [
                'proceed' => false,
                'message' => __('Waiting for a different organization member to confirm this apply.'),
                'tone' => 'error',
            ];
        }

        unset($meta['firewall_approval_pending']);
        $server->update(['meta' => $meta]);
        $server->refresh();

        return ['proceed' => true, 'message' => null, 'tone' => 'none'];
    }
}
