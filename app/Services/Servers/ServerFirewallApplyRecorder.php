<?php

namespace App\Services\Servers;

use App\Jobs\FirewallSyntheticReachabilityJob;
use App\Models\ApiToken;
use App\Models\Server;
use App\Models\ServerFirewallApplyLog;
use App\Models\User;
use App\Services\Integrations\FirewallWebhookDispatcher;
use Illuminate\Support\Str;

/**
 * Persists post-apply metadata, history rows, and optional follow-up jobs/webhooks.
 */
class ServerFirewallApplyRecorder
{
    public function __construct(
        private FirewallRuleStateHasher $hasher,
        private FirewallWebhookDispatcher $webhooks,
    ) {}

    public function recordSuccess(
        Server $server,
        ?User $user,
        ?ApiToken $apiToken,
        string $outputExcerpt,
        string $source = 'apply'
    ): void {
        $h = $this->hasher->hashServerRules($server);
        $meta = $server->meta ?? [];
        $meta['firewall_last_apply_hash'] = $h['hash'];
        $meta['firewall_last_apply_at'] = now()->toIso8601String();
        $server->update(['meta' => $meta]);
        $server->refresh();

        ServerFirewallApplyLog::query()->create([
            'server_id' => $server->id,
            'user_id' => $user?->id,
            'api_token_id' => $apiToken?->id,
            'kind' => 'apply',
            'success' => true,
            'rules_hash' => $h['hash'],
            'rule_count' => $h['count'],
            'message' => Str::limit(trim($outputExcerpt), 2000),
            'meta' => ['source' => $source],
        ]);

        $org = $server->organization;
        if ($org) {
            $this->webhooks->dispatch(
                $org,
                $server,
                'firewall_applied',
                __('Firewall rules applied on :server.', ['server' => $server->name])
            );
        }

        if (config('server_firewall.synthetic_probe.dispatch_after_apply', false)) {
            FirewallSyntheticReachabilityJob::dispatch($server->id);
        }
    }

    public function recordFailure(
        Server $server,
        ?User $user,
        ?ApiToken $apiToken,
        string $error,
        string $source = 'apply'
    ): void {
        $h = $this->hasher->hashServerRules($server);

        ServerFirewallApplyLog::query()->create([
            'server_id' => $server->id,
            'user_id' => $user?->id,
            'api_token_id' => $apiToken?->id,
            'kind' => 'apply',
            'success' => false,
            'rules_hash' => $h['hash'],
            'rule_count' => $h['count'],
            'message' => Str::limit(trim($error), 2000),
            'meta' => ['source' => $source],
        ]);
    }
}
