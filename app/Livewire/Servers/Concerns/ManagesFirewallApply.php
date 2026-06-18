<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Jobs\ApplyFirewallJob;
use App\Services\Servers\ServerFirewallApplyRecorder;
use App\Services\Servers\ServerFirewallAuditLogger;
use App\Services\Servers\ServerFirewallProvisioner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesFirewallApply
{
    /**
     * Dry-run preview state. {@see previewApplyFirewall()} fills $apply_preview_lines with the
     * exact `ufw <fragment>` commands the next apply will run, in the same order the provisioner
     * emits them, and flips the modal open. {@see applyFirewall()} is reached only after the
     * operator confirms.
     *
     * @var list<string>
     */
    public array $apply_preview_lines = [];

    public bool $apply_preview_open = false;

    /**
     * Build the ordered list of UFW commands the upcoming apply will run, in the same order as
     * {@see ServerFirewallProvisioner::apply()}: defaults → SSH safety rail → per-rule fragments
     * → `--force enable`. Then open the preview modal.
     */
    public function previewApplyFirewall(ServerFirewallProvisioner $firewall): void
    {
        $this->authorize('update', $this->server);
        $this->server->refresh();

        $lines = [];

        foreach ($firewall->defaultPoliciesFromMeta($this->server) as $chain => $policy) {
            $lines[] = sprintf('ufw default %s %s', $policy, $chain);
        }

        $loggingLevel = $firewall->loggingLevelFromMeta($this->server);
        if ($loggingLevel !== null) {
            $lines[] = sprintf('ufw logging %s', $loggingLevel);
        }

        $sshPort = (int) ($this->server->ssh_port ?: 22);
        $lines[] = sprintf("ufw allow %d/tcp comment 'Dply: keep SSH reachable'", $sshPort);

        $rules = $this->server->firewallRules()->where('enabled', true)->orderBy('sort_order')->get();
        foreach ($rules as $rule) {
            try {
                $fragment = $firewall->ufwRuleFragment($rule);
            } catch (\Throwable $e) {
                $fragment = '# skipped: '.$e->getMessage();
            }
            $lines[] = 'ufw '.$fragment.($rule->name ? '   # '.$rule->name : '');
        }

        $lines[] = 'ufw --force enable';

        $this->apply_preview_lines = $lines;
        $this->apply_preview_open = true;
    }

    public function closeApplyPreview(): void
    {
        $this->apply_preview_open = false;
        $this->apply_preview_lines = [];
    }

    public function applyFirewall(
        ServerFirewallProvisioner $firewall,
        ServerFirewallAuditLogger $audit,
        ServerFirewallApplyRecorder $recorder,
        bool $override = false,
    ): void {
        $this->authorize('update', $this->server);
        $this->server->refresh();
        if (! $this->disruptiveActionAllowed(__('Apply firewall rules'), $override)) {
            return;
        }

        if ($firewall->sshAccessNotExplicitlyAllowed($this->server) && ! $this->firewall_ack_ssh_risk) {
            $this->toastError(
                __('Check “I understand SSH may be unreachable” below, or add an allow rule for your SSH port, before applying.')
            );

            return;
        }

        if ($this->isApplyBusy()) {
            $this->toastError(__('A firewall apply is already in flight on this server. Wait for it to finish before starting another.'));

            return;
        }

        $runId = (string) Str::ulid();
        $meta = $this->server->fresh()->meta ?? [];
        $meta[config('server_firewall.meta_apply_run_id_key')] = $runId;
        $meta[config('server_firewall.meta_apply_status_key')] = 'queued';
        $meta[config('server_firewall.meta_apply_started_at_key')] = now()->toIso8601String();
        $meta[config('server_firewall.meta_apply_finished_at_key')] = null;
        $meta[config('server_firewall.meta_apply_error_key')] = null;
        $this->server->fresh()->update(['meta' => $meta]);
        $this->server->refresh();

        $this->firewall_ack_ssh_risk = false;
        $this->apply_preview_open = false;
        $this->apply_preview_lines = [];
        ApplyFirewallJob::dispatch($this->server->id, $runId, Auth::id());

        $this->toastSuccess(__('Firewall apply queued — watch the banner for live output. You can leave this page; the job runs on the queue.'));
    }

    /**
     * True while a firewall apply is queued or running. Treats stale entries (older than the
     * threshold) as no-longer-in-flight so a dead worker doesn't permanently block re-dispatch.
     */
    protected function isApplyBusy(): bool
    {
        $meta = $this->server->fresh()->meta ?? [];
        $status = (string) data_get($meta, config('server_firewall.meta_apply_status_key'));

        if (! in_array($status, ['queued', 'running'], true)) {
            return false;
        }

        $startedAt = (string) data_get($meta, config('server_firewall.meta_apply_started_at_key'));
        if ($startedAt === '') {
            return true;
        }
        try {
            return ! Carbon::parse($startedAt)->lt(now()->subSeconds(self::APPLY_STALE_THRESHOLD_SECONDS));
        } catch (\Throwable) {
            return false;
        }
    }

    public function pollApplyStatus(): void
    {
        $this->server->refresh();
    }

    public function dismissApplyBanner(): void
    {
        $this->authorize('update', $this->server);

        $status = (string) data_get($this->server->fresh()->meta ?? [], config('server_firewall.meta_apply_status_key'));
        if (in_array($status, ['queued', 'running'], true)) {
            return;
        }

        $meta = $this->server->fresh()->meta ?? [];
        unset(
            $meta[config('server_firewall.meta_apply_run_id_key')],
            $meta[config('server_firewall.meta_apply_status_key')],
            $meta[config('server_firewall.meta_apply_started_at_key')],
            $meta[config('server_firewall.meta_apply_finished_at_key')],
            $meta[config('server_firewall.meta_apply_error_key')],
        );
        $this->server->fresh()->update(['meta' => $meta]);
        $this->server->refresh();
    }

    /**
     * Streaming output buffer for the active (or most recent) apply run. Empty list when no
     * run is tracked or the cache TTL has lapsed.
     *
     * @return list<string>
     */
    public function getApplyOutputLinesProperty(): array
    {
        $runId = (string) data_get($this->server->meta ?? [], config('server_firewall.meta_apply_run_id_key'));
        if ($runId === '') {
            return [];
        }
        $payload = Cache::get((string) config('server_firewall.apply_output_cache_key_prefix', 'firewall_apply_output:').$runId);
        if (! is_array($payload)) {
            return [];
        }
        $lines = $payload['lines'] ?? [];

        return is_array($lines) ? array_values(array_filter($lines, 'is_string')) : [];
    }

    /**
     * Split an SSH command-output blob into transcript lines, dropping empty lines and
     * capping the total so an enormous ufw status doesn't overwhelm the banner cache.
     *
     * @return list<string>
     */
    protected function splitOutputForBanner(string $blob, int $maxLines = 200): array
    {
        $lines = array_values(array_filter(
            array_map('rtrim', preg_split("/\r?\n/", trim($blob)) ?: []),
            static fn (string $l): bool => $l !== '',
        ));

        return count($lines) > $maxLines
            ? array_merge(array_slice($lines, 0, $maxLines), [sprintf('… (%d more lines truncated)', count($lines) - $maxLines)])
            : $lines;
    }
}
