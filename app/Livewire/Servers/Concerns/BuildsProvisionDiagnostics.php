<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Models\ServerProvisionArtifact;
use App\Models\ServerProvisionRun;
use App\Modules\TaskRunner\Models\Task;
use App\Support\Servers\ClassifyProvisionFailure;
use App\Support\Servers\ProvisionVerificationSummary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait BuildsProvisionDiagnostics
{


    /**
     * Extract a one-line "why did this fail" headline + a few supporting lines from the
     * captured step output (or full task output as a fallback). Surfaces the actual error
     * message to the user instead of a generic "step failed before finishing" framing.
     *
     * @param  array{key:string,label:string,state:string,detail:?string,output:?string,duration:?string}|null  $failedStep
     * @return array{headline:string, context:list<string>, exit_code:?int}|null
     */
    protected function failureReason(?Task $task, ?array $failedStep): ?array
    {
        if ($failedStep === null) {
            return null;
        }

        $source = trim((string) ($failedStep['output'] ?? ''));
        if ($source === '' && $task !== null && is_string($task->output)) {
            $source = trim($task->output);
        }
        if ($source === '') {
            return null;
        }

        $lines = preg_split('/\r\n|\r|\n/', $source) ?: [];

        // Drop noise: rollback markers, step markers, empty lines, locale warnings.
        $meaningful = [];
        foreach ($lines as $line) {
            $trimmed = trim((string) $line);
            if ($trimmed === '') {
                continue;
            }
            if (str_contains($trimmed, '[dply-rollback]') || str_contains($trimmed, '[dply-step]')) {
                continue;
            }
            $meaningful[] = $trimmed;
        }
        if ($meaningful === []) {
            return null;
        }

        // High-priority root-cause patterns: when these appear, they're
        // almost always the actual cause of the failure even if a
        // downstream symptom appears later (e.g. a PPA fetch timeout
        // followed by "couldn't find package php8.4-mysql"). Scan the
        // FULL meaningful set (not just the tail) so we catch causes
        // that scrolled past the symptom.
        $rootCausePatterns = [
            '/Could not connect to/i',
            '/Connection (?:timed out|refused)/i',
            '/Failed to fetch/i',
            '/Some index files failed to download/i',
            '/Network is unreachable/i',
            '/Temporary failure resolving/i',
        ];

        // Lower-priority symptom patterns — match these only when no
        // root cause was found. Scanned from the tail backwards.
        $errorPatterns = [
            '/^E:\s/i',                                  // apt
            '/^Err:/i',                                  // apt
            '/^Error:\s/i',                              // generic
            '/^FATAL:/i',
            '/^fatal:/i',
            '/Cannot\s/i',
            '/Permission denied/i',
            '/No such file or directory/i',
            '/command not found/i',
            '/exited with (?:status|code)\s+\d+/i',
            '/Timeout was reached/i',
            '/Failed to (?:start|connect|enable)/i',
            '/dpkg:\s+error/i',
            '/Sub-process\s+\S+\s+returned/i',
        ];

        $headline = null;
        $contextStart = max(0, count($meaningful) - 8);
        $tail = array_slice($meaningful, $contextStart);

        foreach ($meaningful as $line) {
            foreach ($rootCausePatterns as $pattern) {
                if (preg_match($pattern, $line) === 1) {
                    $headline = $line;
                    break 2;
                }
            }
        }

        if ($headline === null) {
            foreach (array_reverse($tail, true) as $line) {
                foreach ($errorPatterns as $pattern) {
                    if (preg_match($pattern, $line) === 1) {
                        $headline = $line;
                        break 2;
                    }
                }
            }
        }

        if ($headline === null) {
            // Fall back to the last meaningful line.
            $headline = end($tail) ?: end($meaningful);
        }

        $exitCode = null;
        if (preg_match('/exited with (?:status|code)\s+(\d+)/i', $source, $m) === 1) {
            $exitCode = (int) $m[1];
        } elseif ($task && $task->exit_code !== null) {
            $exitCode = (int) $task->exit_code;
        }

        // Trim very long lines for the headline so we don't overflow the banner.
        if (mb_strlen($headline) > 280) {
            $headline = mb_substr($headline, 0, 277).'…';
        }

        return [
            'headline' => $headline,
            'context' => array_slice($meaningful, max(0, count($meaningful) - 5)),
            'exit_code' => $exitCode,
        ];
    }

    /**
     * Parse `[dply-rollback] <relpath> :: <action> :: <detail>` markers emitted by the
     * bootstrap script's ERR trap + dply_restore_backups helper. Lets us tell the user
     * whether automatic rollback ran and what files it touched.
     *
     * @return array{
     *     triggered: bool,
     *     restored: list<string>,
     *     removed: list<string>,
     *     other: list<array{path:string, action:string, detail:string}>,
     *     total: int
     * }|null
     */
    protected function rollbackSummary(?Task $task): ?array
    {
        if (! $task || ! is_string($task->output) || trim($task->output) === '') {
            return null;
        }

        $triggered = false;
        $restored = [];
        $removed = [];
        $other = [];

        $lines = preg_split('/\r\n|\r|\n/', $task->output) ?: [];
        foreach ($lines as $line) {
            if (! str_contains($line, '[dply-rollback]')) {
                continue;
            }

            // Strip prefix and split "<path> :: <action> :: <detail>".
            $body = trim((string) preg_replace('/^.*\[dply-rollback\]\s*/', '', $line));
            $segments = array_map('trim', explode('::', $body, 3));
            $path = $segments[0] ?? '';
            $action = strtolower($segments[1] ?? '');
            $detail = $segments[2] ?? '';

            if ($path === 'automatic' && $action === 'started') {
                $triggered = true;

                continue;
            }

            if ($action === 'restored') {
                $restored[] = $path;
            } elseif ($action === 'removed') {
                $removed[] = $path;
            } elseif ($action !== 'checkpoint' && $action !== '') {
                $other[] = ['path' => $path, 'action' => $action, 'detail' => $detail];
            }
        }

        if (! $triggered && $restored === [] && $removed === [] && $other === []) {
            return null;
        }

        return [
            'triggered' => $triggered,
            'restored' => $restored,
            'removed' => $removed,
            'other' => $other,
            'total' => count($restored) + count($removed),
        ];
    }

    /**
     * @param  Collection<int, mixed>  $artifacts
     * @return list<array{key:string,label:string,status:string,detail:?string}>
     */
    protected function verificationChecks(Collection $artifacts): array
    {
        /** @var ServerProvisionArtifact|null $artifact */
        $artifact = $artifacts->firstWhere('type', 'verification_report');

        return ProvisionVerificationSummary::fromArtifact($artifact);
    }

    /**
     * @param  list<array{key:string,label:string,state:string,detail:?string,output:?string,duration:?string}>  $steps
     * @param  list<array{key:string,label:string,status:string,detail:?string}>  $verificationChecks
     * @return array{code:string,label:string,detail:string}|null
     */
    protected function failureClassification(?Task $task, array $steps, ?ServerProvisionRun $run, array $verificationChecks): ?array
    {
        if (! $run || $run->status !== 'failed') {
            return null;
        }

        $failedStep = collect($steps)->firstWhere('state', 'failed');

        return ClassifyProvisionFailure::classify(
            $failedStep['label'] ?? null,
            $task?->tailOutput(12),
            $verificationChecks,
            $run->rollback_status,
        );
    }

    /**
     * @param  list<array{key:string,label:string,state:string,detail:?string,output:?string,duration:?string}>  $steps
     * @param  list<array{key:string,label:string,status:string,detail:?string}>  $verificationChecks
     * @param  array{code:string,label:string,detail:string}|null  $failureClassification
     * @return array{summary:string,actions:list<string>,commands:list<string>}|null
     */
    protected function repairGuidance(?Task $task, array $steps, ?ServerProvisionRun $run, array $verificationChecks, ?array $failureClassification): ?array
    {
        if (! $run || ! in_array($run->status, ['failed', 'cancelled'], true)) {
            return null;
        }

        $failedStep = collect($steps)->firstWhere('state', 'failed');
        $failingChecks = collect($verificationChecks)
            ->where('status', '!=', 'ok')
            ->pluck('label')
            ->values()
            ->all();

        $actions = [];
        $commands = [];

        if ($run->rollback_status === 'repair_required') {
            $actions[] = 'Inspect the generated config and service state before reusing this server.';
            $actions[] = 'Remove the server if you want a fully clean rebuild.';
        } else {
            $actions[] = 'Resume install after reviewing the failed step output.';
            $actions[] = 'Inspect the generated configs and recent task output if the rerun fails again.';
        }

        if ($failingChecks !== []) {
            $actions[] = 'Review the failed verification checks: '.implode(', ', $failingChecks).'.';
        }

        if (($failureClassification['code'] ?? null) === 'package_repo_unreachable') {
            $actions = [
                'This usually means a package mirror or PPA was briefly unreachable. Click Re-run setup to try again.',
                'If it keeps failing, check whether the server can reach archive.ubuntu.com and ppa.launchpadcontent.net (HTTPS, port 443).',
                'In rare cases the PPA itself is offline — in that case waiting a few minutes is the only fix.',
            ];
            $commands[] = 'curl -I https://ppa.launchpadcontent.net';
            $commands[] = 'sudo apt-get update';
        } elseif (($failureClassification['code'] ?? null) === 'config_validation') {
            $commands[] = 'sudo nginx -t';
            $commands[] = 'sudo haproxy -c -f /etc/haproxy/haproxy.cfg';
        } elseif (($failureClassification['code'] ?? null) === 'service_startup') {
            $commands[] = 'sudo systemctl status nginx --no-pager';
            $commands[] = 'sudo systemctl status php8.3-fpm --no-pager';
        } else {
            $commands[] = 'sudo journalctl -xe --no-pager | tail -n 80';
            $commands[] = 'sudo systemctl --failed';
        }

        return [
            'summary' => $failedStep
                ? 'The run stopped during "'.$failedStep['label'].'". Review the output and the suggested actions before retrying.'
                : 'Provisioning did not complete. Review the latest output and suggested actions before retrying.',
            'actions' => array_values(array_unique($actions)),
            'commands' => array_values(array_unique($commands)),
        ];
    }

    /**
     * @param  Collection<int, mixed>  $artifacts
     * @return array{role:?string,webserver:?string,php_version:?string,database:?string,cache_service:?string,deploy_user:?string,expected_services:list<string>,paths:array<string,string>,config_files:list<string>}|null
     */
    protected function stackSummary(Collection $artifacts): ?array
    {
        /** @var ServerProvisionArtifact|null $artifact */
        $artifact = $artifacts->firstWhere('type', 'stack_summary');
        if (! $artifact) {
            return null;
        }

        $decoded = $artifact->metadata;
        if (! is_array($decoded) || $decoded === []) {
            $decoded = json_decode((string) $artifact->content, true);
        }

        if (! is_array($decoded) || $decoded === []) {
            return null;
        }

        return [
            'role' => isset($decoded['role']) ? (string) $decoded['role'] : null,
            'webserver' => isset($decoded['webserver']) ? (string) $decoded['webserver'] : null,
            'php_version' => isset($decoded['php_version']) ? (string) $decoded['php_version'] : null,
            'database' => isset($decoded['database']) ? (string) $decoded['database'] : null,
            'cache_service' => isset($decoded['cache_service']) ? (string) $decoded['cache_service'] : null,
            'deploy_user' => isset($decoded['deploy_user']) ? (string) $decoded['deploy_user'] : null,
            'expected_services' => array_values(array_filter(array_map('strval', is_array($decoded['expected_services'] ?? null) ? $decoded['expected_services'] : []))),
            'paths' => is_array($decoded['paths'] ?? null) ? $decoded['paths'] : [],
            'config_files' => array_values(array_filter(array_map('strval', is_array($decoded['config_files'] ?? null) ? $decoded['config_files'] : []))),
        ];
    }

    /**
     * @param  list<array{key:string,label:string,state:string,detail:?string,output:?string,duration:?string,eta:?array{seconds:int,samples:int}}>  $steps
     * @return array{eta:string,eta_samples:?int,running_for:string,last_output:?string,stalled:bool,warning:?string}|null
     */
    protected function stallState(?Task $task, array $steps): ?array
    {
        if (! $task || ! $task->status->isActive()) {
            return null;
        }

        $activeStep = collect($steps)->firstWhere('state', 'active');
        $now = now();
        // Carbon 3's diffInSeconds() returns a SIGNED float — when the
        // argument is in the past it comes back negative, and max(0, …)
        // then clamps the timer to zero. Operators saw "Running for 0s"
        // sit there forever even after several minutes for that reason.
        // Pass `true` for absolute, then int-cast — keeps the timer
        // monotonic regardless of which Carbon version is active.
        $secondsSinceUpdate = (int) abs($now->diffInSeconds($task->updated_at ?? $task->started_at ?? $now, true));
        $secondsRunning = (int) abs($now->diffInSeconds($task->started_at ?? $task->created_at ?? $now, true));

        // Prefer the data-driven ETA from past runs over the static
        // "Usually X minutes" copy. The eta payload only lands on
        // script_* steps (cloud-side keys never have one) and only
        // when sample size cleared the configured threshold.
        $etaSamples = null;
        $stepEta = $activeStep['eta'] ?? null;
        if (is_array($stepEta) && ($stepEta['seconds'] ?? 0) > 0) {
            $eta = sprintf('Avg %s', $this->formatRunDuration((int) $stepEta['seconds']));
            $etaSamples = (int) ($stepEta['samples'] ?? 0);
        } else {
            $eta = match ($activeStep['key'] ?? null) {
                'provisioning', 'ip', 'ssh' => 'Usually 2-5 minutes',
                'setup' => 'Usually 5-10 minutes',
                default => 'Usually a few minutes',
            };
        }

        // Stall heuristics in minutes (integer thresholds are fine here
        // because we round up to favour the operator: a 2m59s gap should
        // still tip into "looks stalled" sooner rather than later).
        $minutesSinceUpdate = (int) ceil($secondsSinceUpdate / 60);
        $minutesRunning = (int) ceil($secondsRunning / 60);
        $stalled = $minutesSinceUpdate >= 3 || $minutesRunning >= 8;

        return [
            'eta' => $eta,
            'eta_samples' => $etaSamples,
            'running_for' => 'Running for '.$this->formatRunDuration($secondsRunning),
            // Only surface this when the gap is meaningful; under 30s
            // is just normal poll cadence and would flicker on/off.
            'last_output' => $secondsSinceUpdate >= 30
                ? 'No new output for '.$this->formatRunDuration($secondsSinceUpdate)
                : null,
            'stalled' => $stalled,
            'warning' => $stalled ? 'This run may be stalled. Review the latest output or cancel and retry if it does not recover soon.' : null,
        ];
    }

    private function formatRunDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.'s';
        }

        $minutes = intdiv($seconds, 60);
        $remainder = $seconds % 60;

        if ($minutes < 10 && $remainder > 0) {
            return "{$minutes}m {$remainder}s";
        }

        return $minutes.'m';
    }
}
