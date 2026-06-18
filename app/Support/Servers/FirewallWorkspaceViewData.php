<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Livewire\Servers\WorkspaceFirewall;
use App\Models\Server;
use App\Models\ServerFirewallApplyLog;
use App\Models\ServerFirewallAuditEvent;
use Illuminate\Support\Carbon;

/**
 * View-model for the server Firewall workspace blade tree. Keeps banner/setup
 * and tab preamble logic out of {@see resources/views/livewire/servers/workspace-firewall.blade.php}.
 */
final class FirewallWorkspaceViewData
{
    /**
     * @param  list<array<string, mixed>>  $activityItems
     * @return array<string, mixed>
     */
    public static function for(
        Server $server,
        WorkspaceFirewall $component,
        bool $includeRulesContext = false,
        bool $includeActivityContext = false,
        array $activityItems = [],
    ): array {
        $card = 'dply-card overflow-hidden';
        $opsReady = $server->isReady() && $server->ssh_private_key;

        $applyStatus = (string) data_get($server->meta ?? [], config('server_firewall.meta_apply_status_key'));
        $applyRunId = (string) data_get($server->meta ?? [], config('server_firewall.meta_apply_run_id_key'));
        $applyError = (string) data_get($server->meta ?? [], config('server_firewall.meta_apply_error_key'));
        $applyFinishedAt = data_get($server->meta ?? [], config('server_firewall.meta_apply_finished_at_key'));
        $applyBusy = in_array($applyStatus, ['queued', 'running'], true);
        $applyShowBanner = $applyRunId !== '' && in_array($applyStatus, ['queued', 'running', 'completed', 'failed'], true);

        $applyMessage = match ($applyStatus) {
            'queued' => __('Firewall apply queued — waiting for a worker to pick it up…'),
            'running' => __('Applying firewall to :host …', ['host' => $server->getSshConnectionString()]),
            'completed' => __('Firewall applied — UFW updated.'),
            'failed' => __('Firewall apply failed.'),
            default => '',
        };

        $applySubtitle = match (true) {
            $applyBusy => __('Refreshing every 4s · safe to leave this page — the job runs on the queue.'),
            $applyStatus === 'failed' && $applyError !== '' => $applyError,
            $applyStatus === 'completed' && $applyFinishedAt => __('Finished :time', ['time' => Carbon::parse($applyFinishedAt)->diffForHumans()]),
            default => null,
        };

        $panelSubtitle = match ($component->panel_event_status) {
            'failed' => null,
            default => __('The host firewall was touched. Output below — dismiss when you\'re done reading.'),
        };

        $hasAdvanced = trim((string) ($component->form->name ?? '')) !== ''
            || trim((string) ($component->form->profile ?? '')) !== ''
            || trim((string) ($component->form->tags ?? '')) !== ''
            || trim((string) ($component->form->runbook_url ?? '')) !== ''
            || trim((string) ($component->form->site_id ?? '')) !== ''
            || trim((string) ($component->form->iface ?? '')) !== '';

        $data = compact(
            'card',
            'opsReady',
            'applyStatus',
            'applyBusy',
            'applyShowBanner',
            'applyMessage',
            'applySubtitle',
            'panelSubtitle',
            'hasAdvanced',
        );

        if ($includeRulesContext) {
            $ruleCount = $server->firewallRules->count();
            $enabledRuleCount = $server->firewallRules->where('enabled', true)->count();
            $lastApplyLog = ServerFirewallApplyLog::query()
                ->where('server_id', $server->id)
                ->orderByDesc('id')
                ->first();
            $defaultPolicyFallbacks = (array) config('server_firewall.default_policy_fallbacks', []);
            $defaultPolicyChains = [
                'incoming' => __('Incoming'),
                'outgoing' => __('Outgoing'),
                'routed' => __('Routed (forwarded)'),
            ];
            $defaultPolicyChoices = (array) config('server_firewall.default_policies', ['allow', 'deny', 'reject']);

            $data = array_merge($data, compact(
                'ruleCount',
                'enabledRuleCount',
                'lastApplyLog',
                'defaultPolicyFallbacks',
                'defaultPolicyChains',
                'defaultPolicyChoices',
            ));
        }

        if ($includeActivityContext) {
            $activityCount = count($activityItems);
            $latestActivity = $activityItems[0]['at'] ?? null;
            $linesOf = static function (?string $message): array {
                if (! is_string($message) || trim($message) === '') {
                    return [];
                }
                $lines = array_values(array_filter(
                    array_map('trim', preg_split("/\r?\n/", $message) ?: []),
                    static fn (string $l): bool => $l !== '',
                ));

                return array_slice($lines, 0, 25);
            };

            $data = array_merge($data, compact(
                'activityCount',
                'latestActivity',
                'linesOf',
            ));
        }

        return $data;
    }
}
