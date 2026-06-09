<?php

declare(strict_types=1);

namespace App\Support\Servers;

use App\Livewire\Servers\WorkspaceMonitor;
use App\Models\Server;
use App\Models\ServerMetricSnapshot;
use Illuminate\Support\Carbon;

/**
 * View-model for the server Monitor workspace blade tree. Keeps catalog/setup
 * and health-preamble logic out of {@see resources/views/livewire/servers/workspace-monitor.blade.php}.
 */
final class MonitorWorkspaceViewData
{
    /**
     * @return array<string, mixed>
     */
    public static function for(
        Server $server,
        WorkspaceMonitor $component,
        ?ServerMetricSnapshot $latest,
        ?array $guestPushVerification,
        ?int $sampleAgeMinutes,
        bool $sampleTimestampInFuture,
        ?string $monitorLastGuestSampleAt,
        int $pollRemoteTaskSeconds,
    ): array {
        $card = 'dply-card overflow-hidden';

        $m = $server->meta ?? [];
        $sshKnown = array_key_exists('monitoring_ssh_reachable', $m);
        $sshOk = (bool) ($m['monitoring_ssh_reachable'] ?? false);
        $pyOk = (bool) ($m['monitoring_python_installed'] ?? false);
        $sshUnreachable = $sshKnown && ! $sshOk;
        $probeAt = isset($m['monitoring_probe_at'])
            ? Carbon::parse($m['monitoring_probe_at'])->timezone(config('app.timezone'))
            : null;
        $lastGuestSampleAt = $monitorLastGuestSampleAt
            ? Carbon::parse($monitorLastGuestSampleAt)->timezone(config('app.timezone'))
            : null;
        $p = $latest?->payload ?? [];

        $fmtBytes = function (?int $b): string {
            if ($b === null || $b <= 0) {
                return '—';
            }
            $units = ['B', 'KB', 'MB', 'GB', 'TB'];
            $i = 0;
            $v = (float) $b;
            while ($v >= 1024 && $i < count($units) - 1) {
                $v /= 1024;
                $i++;
            }

            return number_format($v, $i > 0 ? 1 : 0).' '.$units[$i];
        };

        $fmtRate = function (?float $bytesPerSecond) use ($fmtBytes): string {
            if ($bytesPerSecond === null || $bytesPerSecond < 0) {
                return '—';
            }

            return $fmtBytes((int) round($bytesPerSecond)).'/s';
        };

        $fmtDuration = function (?int $seconds): string {
            if ($seconds === null || $seconds < 0) {
                return '—';
            }

            $days = intdiv($seconds, 86400);
            $hours = intdiv($seconds % 86400, 3600);
            $minutes = intdiv($seconds % 3600, 60);

            if ($days > 0) {
                return "{$days}d {$hours}h";
            }
            if ($hours > 0) {
                return "{$hours}h {$minutes}m";
            }

            return "{$minutes}m";
        };

        $fmtAge = function (?int $minutes) use ($fmtDuration): string {
            if ($minutes === null || $minutes < 0) {
                return '—';
            }
            if ($minutes < 60) {
                return trans_choice(':count minute|:count minutes', $minutes, ['count' => $minutes]);
            }

            return $fmtDuration($minutes * 60);
        };

        $guestPushVerification = $guestPushVerification ?? [];
        $monitorScriptCurrent = (bool) ($guestPushVerification['script_current'] ?? false);
        $monitorEnvDeployed = (bool) ($guestPushVerification['callback_env_deployed'] ?? false);
        $monitorCronCurrent = (bool) ($guestPushVerification['cron_current'] ?? false);
        $monitorSampleFresh = $sampleAgeMinutes !== null
            && $sampleAgeMinutes <= 10
            && ! $sampleTimestampInFuture;
        $monitorHealthy = $monitorScriptCurrent
            && $monitorEnvDeployed
            && $monitorCronCurrent
            && $monitorSampleFresh;

        $remoteSha = $guestPushVerification['remote_sha'] ?? null;

        if ($monitorHealthy) {
            $statusChipClasses = 'bg-emerald-50 text-emerald-900 ring-emerald-200';
            $statusChipIcon = 'heroicon-s-check-circle';
            $statusChipLabel = __('Healthy');
            $headlineCopy = __('Installed and running. The server pushes fresh metrics back to Dply every minute.');
        } elseif (! $monitorSampleFresh && $monitorScriptCurrent && $monitorEnvDeployed && $monitorCronCurrent) {
            $statusChipClasses = 'bg-amber-50 text-amber-900 ring-amber-200';
            $statusChipIcon = 'heroicon-s-exclamation-triangle';
            $statusChipLabel = __('Sample stale');
            $headlineCopy = __('Installed and running, but no fresh sample has arrived. The agent may have stopped pushing — open Diagnostics to repair the cron / callback env.');
        } elseif (! $monitorScriptCurrent && $remoteSha !== null) {
            $statusChipClasses = 'bg-amber-50 text-amber-900 ring-amber-200';
            $statusChipIcon = 'heroicon-s-arrow-path';
            $statusChipLabel = __('Agent update queued');
            $headlineCopy = __('A newer agent script is bundled with Dply. The next healthy callback will redeploy it; you can also repair manually under Diagnostics.');
        } else {
            $statusChipClasses = 'bg-rose-50 text-rose-900 ring-rose-200';
            $statusChipIcon = 'heroicon-s-x-circle';
            $statusChipLabel = __('Not configured');
            $headlineCopy = __('Monitor is installed but its callback wiring is incomplete. Open Diagnostics to repair.');
        }

        $checks = [
            [
                'label' => __('Agent script'),
                'ok' => $monitorScriptCurrent,
                'detail' => $monitorScriptCurrent
                    ? __('Up to date')
                    : ($remoteSha === null ? __('Version unknown') : __('Outdated — redeploy queued')),
            ],
            [
                'label' => __('Callback env'),
                'ok' => $monitorEnvDeployed,
                'detail' => $monitorEnvDeployed ? __('Deployed') : __('Missing on host'),
            ],
            [
                'label' => __('Cron line'),
                'ok' => $monitorCronCurrent,
                'detail' => $monitorCronCurrent ? __('Installed') : __('Missing or stale'),
            ],
            [
                'label' => __('Last sample'),
                'ok' => $monitorSampleFresh,
                'detail' => $sampleTimestampInFuture
                    ? __('Clock skew detected')
                    : ($sampleAgeMinutes !== null
                        ? ($monitorSampleFresh
                            ? __(':age ago', ['age' => $fmtAge($sampleAgeMinutes)])
                            : __(':age ago — stale', ['age' => $fmtAge($sampleAgeMinutes)]))
                        : __('Waiting for first sample')),
            ],
        ];

        $bannerStatus = $component->diagnosticsBannerStatus;
        $bannerKind = $component->remote_output_kind;
        $bannerBusy = in_array($bannerStatus, ['queued', 'running'], true);
        $bannerShow = $bannerStatus !== '' && $bannerKind !== null;
        $bannerHost = $server->getSshConnectionString();
        $bannerMessage = match ([$bannerKind, $bannerStatus]) {
            ['repair', 'queued'] => __('Repair queued — waiting for a worker to pick it up…'),
            ['repair', 'running'] => __('Repairing monitor on :host …', ['host' => $bannerHost]),
            ['repair', 'completed'] => __('Monitor repair complete.'),
            ['repair', 'failed'] => __('Monitor repair failed.'),
            ['diagnostics', 'queued'] => __('Diagnostics queued — waiting for a worker to pick it up…'),
            ['diagnostics', 'running'] => __('Running callback diagnostics on :host …', ['host' => $bannerHost]),
            ['diagnostics', 'completed'] => __('Callback diagnostics finished.'),
            ['diagnostics', 'failed'] => __('Callback diagnostics failed.'),
            ['inspect', 'queued'] => __('Inspect queued — waiting for a worker to pick it up…'),
            ['inspect', 'running'] => __('Inspecting callback env on :host …', ['host' => $bannerHost]),
            ['inspect', 'completed'] => __('Callback env inspection finished.'),
            ['inspect', 'failed'] => __('Callback env inspection failed.'),
            default => '',
        };
        $remoteError = $component->remote_error;
        $bannerSubtitle = $bannerBusy
            ? __('Refreshing every :secs s · safe to leave this page — the job runs on the queue.', ['secs' => $pollRemoteTaskSeconds])
            : ($bannerStatus === 'failed' && is_string($remoteError) && $remoteError !== '' ? $remoteError : null);

        $rangeLabels = [
            '1h' => __('1h'),
            '6h' => __('6h'),
            '24h' => __('24h'),
            '7d' => __('7d'),
            '30d' => __('30d'),
        ];

        $statusTextClass = fn (string $status): string => match ($status) {
            'critical' => 'text-red-600',
            'warning' => 'text-amber-600',
            'healthy' => 'text-emerald-600',
            default => 'text-brand-mist',
        };

        $statusKpiClass = fn (string $status): string => match ($status) {
            'critical' => 'text-red-700',
            'warning' => 'text-amber-700',
            default => 'text-brand-ink',
        };

        $kpiTone = fn (string $status): array => match ($status) {
            'critical' => ['bar' => 'bg-red-500', 'kpi' => 'text-red-700'],
            'warning' => ['bar' => 'bg-amber-500', 'kpi' => 'text-amber-700'],
            'healthy' => ['bar' => 'bg-emerald-500', 'kpi' => 'text-brand-ink'],
            default => ['bar' => 'bg-brand-mist/60', 'kpi' => 'text-brand-ink'],
        };

        return compact(
            'card',
            'm',
            'sshKnown',
            'sshOk',
            'pyOk',
            'sshUnreachable',
            'probeAt',
            'lastGuestSampleAt',
            'p',
            'fmtBytes',
            'fmtRate',
            'fmtDuration',
            'fmtAge',
            'monitorScriptCurrent',
            'monitorEnvDeployed',
            'monitorCronCurrent',
            'monitorSampleFresh',
            'monitorHealthy',
            'remoteSha',
            'statusChipClasses',
            'statusChipIcon',
            'statusChipLabel',
            'headlineCopy',
            'checks',
            'bannerStatus',
            'bannerKind',
            'bannerBusy',
            'bannerShow',
            'bannerHost',
            'bannerMessage',
            'bannerSubtitle',
            'rangeLabels',
            'statusTextClass',
            'statusKpiClass',
            'kpiTone',
        );
    }
}
