<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Server;
use App\Models\ServerCronJob;
use App\Models\Site;
use App\Models\SiteProcess;
use App\Models\SupervisorProgram;
use Illuminate\Support\Collection;

/**
 * The daemon/worker counterpart to {@see SitePipelineAdvisor}. That advisor
 * suggests missing *deploy steps* (e.g. "restart Horizon after deploy"); this
 * one suggests the missing *long-running processes* themselves — the Horizon
 * daemon, a queue worker, the Reverb websocket server, or the scheduler — when
 * the detected stack needs them but no Supervisor program / cron entry runs
 * them yet.
 *
 * Detection reuses the composer.json flags already persisted on the site
 * (laravel_horizon / laravel_reverb), so this is pure + read-only: give it a
 * Site, get suggestions back. Supervisor programs only exist on VM hosts, so
 * non-VM runtimes return nothing.
 */
final class SiteDaemonAdvisor
{
    /**
     * @return list<array{key: string, label: string, reason: string, kind: string, preset: ?string, command: string, priority: string}>
     */
    public static function suggestions(Site $site): array
    {
        $server = $site->server;
        if ($server === null || ! $server->isVmHost()) {
            return [];
        }
        if ($site->usesFunctionsRuntime() || $site->usesEdgeRuntime()) {
            return [];
        }
        if (! $site->isLaravelFrameworkDetected()) {
            return [];
        }

        $detection = $site->resolvedRuntimeAppDetection() ?? [];

        // Active Supervisor programs that could already cover a daemon — either
        // scoped to this site or server-wide (site_id null). Match on the
        // structured program_type first, then fall back to the command text so
        // a hand-rolled "custom" program still counts as coverage.
        $programs = $server->supervisorPrograms()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('site_id')->orWhere('site_id', $site->id))
            ->get(['program_type', 'command']);

        // The VM-native way to run a worker is a SiteProcess (materialised into a
        // systemd unit by SiteSystemdProvisioner) — NOT a SupervisorProgram. So
        // a Horizon/queue/Reverb worker can be fully running via systemd and
        // leave the supervisor table empty. Consider active SiteProcesses too,
        // or we'd wrongly tell an operator their Horizon "isn't running".
        $processes = $site->processes()
            ->where('is_active', true)
            ->get(['type', 'command']);

        $covers = static function (string $type, string $needle) use ($programs, $processes): bool {
            $inSupervisor = $programs->contains(function (SupervisorProgram $p) use ($type, $needle): bool {
                if ((string) $p->program_type === $type) {
                    return true;
                }

                return str_contains(strtolower((string) $p->command), $needle);
            });
            if ($inSupervisor) {
                return true;
            }

            // A SiteProcess worker/custom whose command runs this daemon.
            return $processes->contains(
                fn (SiteProcess $p): bool => str_contains(strtolower((string) $p->command), $needle)
            );
        };

        $out = [];

        $hasHorizon = ! empty($detection['laravel_horizon']);
        $hasReverb = ! empty($detection['laravel_reverb']);

        // ---- Horizon ----------------------------------------------------
        if ($hasHorizon && ! $covers('horizon', 'horizon')) {
            $out[] = self::make(
                'horizon',
                __('Run Horizon'),
                __('Laravel Horizon is installed but no daemon runs it — queued jobs never process. Add a Horizon program.'),
                'supervisor',
                'laravel-horizon',
                'php artisan horizon',
                'high',
            );
        }

        // ---- Queue worker (only when not using Horizon) -----------------
        // Horizon supersedes a bare queue:work; suggesting both would be noise.
        if (! $hasHorizon && ! $covers('queue', 'queue:work')) {
            $out[] = self::make(
                'queue',
                __('Run a queue worker'),
                __('No queue worker is running — anything dispatched to a queue (mail, jobs, notifications) will never run. Add a queue:work program.'),
                'supervisor',
                'laravel-queue',
                'php artisan queue:work',
                'high',
            );
        }

        // ---- Reverb -----------------------------------------------------
        if ($hasReverb && ! $covers('reverb', 'reverb:start')) {
            $out[] = self::make(
                'reverb',
                __('Run Reverb'),
                __('Laravel Reverb is installed but no daemon serves it — websocket connections will fail. Add a Reverb program.'),
                'supervisor',
                'reverb',
                'php artisan reverb:start',
                'high',
            );
        }

        // ---- Scheduler --------------------------------------------------
        // Covered by either a schedule:work Supervisor program or a
        // schedule:run cron entry (the two ways to run Laravel's scheduler).
        if (! self::schedulerCovered($site, $server, $programs, $processes)) {
            $out[] = self::make(
                'scheduler',
                __('Run the scheduler'),
                __('No scheduler is running — scheduled tasks defined in the app won\'t fire. Add the Laravel scheduler.'),
                'scheduler',
                'laravel-schedule',
                'php artisan schedule:work',
                'medium',
            );
        }

        return $out;
    }

    /**
     * @param  Collection<int, SupervisorProgram>  $programs
     * @param  Collection<int, SiteProcess>  $processes
     */
    private static function schedulerCovered(Site $site, Server $server, $programs, $processes): bool
    {
        // schedule:work / schedule:run as a long-running Supervisor program.
        $byProgram = $programs->contains(
            fn (SupervisorProgram $p): bool => str_contains(strtolower((string) $p->command), 'schedule:work')
                || str_contains(strtolower((string) $p->command), 'schedule:run')
        );
        if ($byProgram) {
            return true;
        }

        // …or as a SiteProcess (scheduler type, or a command running the scheduler),
        // which is how the VM systemd path runs it.
        $byProcess = $processes->contains(
            fn (SiteProcess $p): bool => $p->type === SiteProcess::TYPE_SCHEDULER
                || str_contains(strtolower((string) $p->command), 'schedule:work')
                || str_contains(strtolower((string) $p->command), 'schedule:run')
        );
        if ($byProcess) {
            return true;
        }

        // schedule:run / schedule:work as a cron entry for this site (or
        // server-wide). Mirrors SchedulerCardsBuilder's "detected" patterns.
        return ServerCronJob::query()
            ->where('server_id', $server->id)
            ->where(fn ($q) => $q->whereNull('site_id')->orWhere('site_id', $site->id))
            ->where(fn ($q) => $q->where('command', 'like', '%schedule:run%')
                ->orWhere('command', 'like', '%schedule:work%'))
            ->exists();
    }

    /**
     * @return array{key: string, label: string, reason: string, kind: string, preset: ?string, command: string, priority: string}
     */
    private static function make(string $key, string $label, string $reason, string $kind, ?string $preset, string $command, string $priority): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'reason' => $reason,
            'kind' => $kind,
            'preset' => $preset,
            'command' => $command,
            'priority' => $priority,
        ];
    }
}
