<?php

declare(strict_types=1);

namespace App\Livewire\Servers\Concerns;

use App\Models\Server;
use App\Modules\TaskRunner\Models\Task;
use App\Services\Servers\ProvisionStepEtaService;
use App\Support\Servers\ProvisionStepDurations;
use App\Support\Servers\ProvisionStepSnapshots;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait BuildsProvisionStepView
{


    /**
     * @return list<array{key:string,label:string,state:string,detail:?string,output:?string,duration:?string}>
     */
    protected function steps(?Task $task): array
    {
        $server = $this->server;
        $scriptSteps = $this->scriptSteps($task);

        $steps = [
            ['key' => 'queued', 'label' => __('Request queued with provider')],
            ['key' => 'provisioning', 'label' => __('Provisioning server')],
            ['key' => 'ip', 'label' => __('Waiting for server IP')],
            ['key' => 'ssh', 'label' => __('Waiting for SSH')],
            ['key' => 'ready', 'label' => __('Server ready')],
        ];

        if ($scriptSteps !== []) {
            array_splice($steps, 4, 0, $scriptSteps);
        } else {
            array_splice($steps, 4, 0, [[
                'key' => 'setup',
                'label' => __('Running server setup'),
            ]]);
        }

        $activeKey = 'queued';
        $failedKey = null;
        $scriptStepKeys = array_column($scriptSteps, 'key');
        $lastSeenScriptKey = $this->lastSeenScriptStepKey($task, $scriptSteps);

        // A provision/setup task can reach a terminal *failed* state
        // (failed / timeout / connection_failed / upload_failed) without
        // anything having flipped the server's own status flags — e.g. the
        // setup job died mid-run, or the result callback from the box never
        // landed. When that happens the branches below compute an active step
        // from the now-stale server status and the view renders a spinner
        // forever; stallState() also bails because the task is no longer
        // active, so the operator never sees the actual error. Treat a
        // terminally-failed task as a journey failure too, so the failed-step
        // banner (with the captured output / failure reason) surfaces. We
        // skip this while an auto-retry is still pending — that state owns its
        // own "Retrying" UI — and once the server is genuinely done.
        $autoRetryAt = isset($server->meta['auto_retry_at'])
            ? Carbon::parse((string) $server->meta['auto_retry_at'])
            : null;
        $autoRetryPending = $autoRetryAt !== null && $autoRetryAt->isFuture();
        $serverReady = $server->status === Server::STATUS_READY
            && $server->setup_status === Server::SETUP_STATUS_DONE;
        $taskFailed = $task !== null
            && $task->status->isFailed()
            && ! $serverReady
            && ! $autoRetryPending;

        // The task drives the *setup* phase (it's only dispatched once SSH is
        // up), so a failed task is a setup-side failure and maps to a script
        // step — same as setup_status === FAILED. A pure cloud-side error
        // (server ERROR, setup never started, no task) still maps to
        // 'provisioning'.
        $failedDuringSetup = $server->setup_status === Server::SETUP_STATUS_FAILED || $taskFailed;

        if ($server->status === Server::STATUS_ERROR || $failedDuringSetup) {
            $activeKey = $failedDuringSetup
                ? ($lastSeenScriptKey ?? ($scriptStepKeys[0] ?? 'setup'))
                : 'provisioning';
            $failedKey = $activeKey;
        } elseif ($server->status === Server::STATUS_PENDING) {
            $activeKey = 'queued';
        } elseif ($server->status === Server::STATUS_PROVISIONING) {
            $activeKey = filled($server->ip_address) ? 'ssh' : 'provisioning';
        } elseif ($server->status === Server::STATUS_READY && $server->setup_status === Server::SETUP_STATUS_PENDING) {
            $activeKey = 'ssh';
        } elseif ($server->status === Server::STATUS_READY && $server->setup_status === Server::SETUP_STATUS_RUNNING) {
            $activeKey = $lastSeenScriptKey ?? ($scriptStepKeys[0] ?? 'setup');
        } elseif ($server->status === Server::STATUS_READY) {
            // Only flip to the terminal 'ready' step once setup is *actually*
            // done. Previously this branch matched on status alone, which
            // caught the brief window after SSH comes up but before the
            // setup job has stamped setup_status (= null) — and marked
            // every cloud + setup step "completed", showing both progress
            // bars at 100% on a server that hadn't started running its
            // bash provision yet. Treat null/unknown like PENDING so the
            // journey holds on 'ssh' until the setup job takes over.
            $activeKey = $server->setup_status === Server::SETUP_STATUS_DONE ? 'ready' : 'ssh';
        }

        $stepIndex = array_flip(array_column($steps, 'key'));
        $activeIndex = $stepIndex[$activeKey] ?? 0;

        // Bulk-resolve ETAs for every script step in one query. The
        // step key for script steps IS the label hash (both come from
        // ProvisionStepSnapshots::keyForLabel), so we can hand the keys
        // directly to the service. Cloud-side steps (queued, provisioning,
        // ip, ssh, ready, setup placeholder) have no historical row and
        // the lookup just returns nothing for them.
        $etaByKey = app(ProvisionStepEtaService::class)
            ->averagesForLabels(
                array_values(array_filter(
                    array_column($steps, 'key'),
                    static fn (string $k): bool => str_starts_with($k, 'script_'),
                )),
                $server->organization,
            );

        return array_map(function (array $step, int $index) use ($activeIndex, $failedKey, $task, $server, $etaByKey): array {
            $state = 'pending';

            if ($failedKey === $step['key']) {
                $state = 'failed';
            } elseif ($index < $activeIndex || ($step['key'] === 'ready' && $activeIndex === $index)) {
                $state = 'completed';
            } elseif ($index === $activeIndex) {
                $state = 'active';
            }

            if ($state === 'pending' && $this->stepHasPersistedSnapshot($server, $step['key'])) {
                $state = 'completed';
            }

            return [
                'key' => $step['key'],
                'label' => $step['label'],
                'state' => $state,
                'detail' => $this->stepDetail($step['key'], $task, $server, $state),
                'output' => $this->stepOutput($step['key'], $task, $server, $state),
                'duration' => $this->stepDuration($step['key'], $task, $server, $state),
                // null when no historical average is available (cold start
                // org, or fewer than step_eta_min_samples runs for this
                // step). View should fall back to the static "Usually X"
                // copy when this is missing.
                'eta' => $etaByKey[$step['key']] ?? null,
            ];
        }, $steps, array_keys($steps));
    }

    protected function stepDetail(string $key, ?Task $task, Server $server, string $state): ?string
    {
        $scriptLabel = $this->scriptStepLabelForKey($task, $key);
        if ($scriptLabel !== null) {
            $stepOutput = $this->persistedStepOutput($server, $key) ?? $this->scriptStepOutput($task, $scriptLabel);

            return match ($state) {
                'active' => $this->scriptStepOutputTail($task, $scriptLabel) ?: __('This setup step is currently running.'),
                'failed' => __('This setup step failed before finishing.'),
                'completed' => $this->stepWasSkipped($stepOutput)
                    ? __('Skipped because the required software was already installed.')
                    : __('Completed during server setup.'),
                default => null,
            };
        }

        return match ($key) {
            'queued' => $state === 'active' ? __('Your request has been accepted and is waiting to start provisioning.') : null,
            'provisioning' => $state === 'failed'
                ? __('Provisioning hit an error before the server became reachable.')
                : __('Dply is waiting for the provider to finish building the server.'),
            'ip' => filled($server->ip_address)
                ? __('IP assigned: :ip', ['ip' => $server->ip_address])
                : __('The server will move forward once a public IP is available.'),
            'ssh' => $state === 'active'
                ? __('The server is reachable enough to continue, but SSH setup has not started yet.')
                : __('Dply will continue once SSH is ready.'),
            'setup' => $state === 'failed'
                ? __('The server setup task failed before finishing.')
                : ($task?->tailOutput(3) ?: __('Applying the selected stack and packages.')),
            'ready' => __('The server is ready for normal workspace operations.'),
            default => null,
        };
    }

    protected function stepDuration(string $key, ?Task $task, Server $server, string $state): ?string
    {
        if ($state !== 'active' && $state !== 'completed') {
            return null;
        }

        $isScriptStep = $key === 'setup' || $this->scriptStepLabelForKey($task, $key) !== null;

        if (! $isScriptStep) {
            // Cloud-side steps (queued / provisioning / ip / ssh / ready) —
            // use the elapsed-since-server-created proxy. We don't track
            // these steps in the duration table because their timing is
            // owned by the cloud provider, not the bash script.
            return $server->created_at?->diffForHumans(now(), true);
        }

        if (! $task) {
            return null;
        }

        // Per-step durations come from the `[dply-step-end]` markers
        // emitted by ServerProvisionCommandBuilder::withStep(). For a
        // step that's already completed we have the recorded value
        // directly; for the *active* step there's no end marker yet,
        // so we approximate "running for" as
        //   (task elapsed) - (sum of all completed step durations).
        // That folds out the time spent on prior steps and leaves only
        // the time accumulated since this step started — which used to
        // be wrong, the active script step was showing the entire task
        // wall-clock instead of its own slice.
        $endDurations = $this->stepEndDurations($task);

        if ($state === 'completed' && $key !== 'setup') {
            $hash = $key; // script_<md5> already matches label_hash
            if (isset($endDurations[$hash])) {
                return $this->formatRunDuration($endDurations[$hash]);
            }
        }

        if ($state === 'active') {
            $started = $task->started_at ?? $task->created_at;
            if ($started === null) {
                return null;
            }

            $taskElapsed = (int) abs(now()->diffInSeconds($started, true));
            $completedTotal = array_sum($endDurations);
            $sliceSeconds = max(0, $taskElapsed - $completedTotal);

            return $this->formatRunDuration($sliceSeconds);
        }

        return $task->getDurationForHumans();
    }

    private function stepEndDurations(Task $task): array
    {
        $cacheKey = (string) $task->id.'@'.(string) ($task->updated_at?->timestamp ?? 0);
        if (array_key_exists($cacheKey, $this->stepEndDurationsCache)) {
            return $this->stepEndDurationsCache[$cacheKey];
        }

        $output = is_string($task->output) ? $task->output : '';
        $rows = ProvisionStepDurations::parse($output);

        $map = [];
        foreach ($rows as $row) {
            // Resumed-skip rows have duration_seconds = 0; ignoring them
            // would still be correct here because the active-step math
            // relies on summing real elapsed seconds, not a count.
            $map[$row['label_hash']] = ($map[$row['label_hash']] ?? 0) + (int) $row['duration_seconds'];
        }

        return $this->stepEndDurationsCache[$cacheKey] = $map;
    }

    protected function stepOutput(string $key, ?Task $task, Server $server, string $state): ?string
    {
        $scriptLabel = $this->scriptStepLabelForKey($task, $key);
        if ($scriptLabel !== null) {
            $stepSpecific = $this->persistedStepOutput($server, $key) ?? $this->scriptStepOutput($task, $scriptLabel);
            if ($stepSpecific !== null) {
                return $stepSpecific;
            }

            // Step marker hasn't appeared yet — fall back to the latest raw output so the user still sees activity.
            if ($state === 'active' && $task) {
                $output = trim((string) $task->tailOutput(40));

                return $output !== '' ? $output : null;
            }

            return null;
        }

        if ($key === 'setup' && $task && in_array($state, ['active', 'failed', 'completed'], true)) {
            $output = trim((string) $task->tailOutput(40));

            return $output !== '' ? $output : null;
        }

        return null;
    }

    /**
     * Raw tail of the task output regardless of step framing — gives the user a "tail -f" view of progress.
     */
    protected function liveTaskOutput(?Task $task): ?string
    {
        if (! $task) {
            return null;
        }

        $output = trim((string) $task->tailOutput(150));

        return $output !== '' ? $output : null;
    }

    protected function liveTaskOutputLineCount(?Task $task): int
    {
        if (! $task || ! is_string($task->output) || trim($task->output) === '') {
            return 0;
        }

        return count(preg_split('/\r\n|\r|\n/', $task->output) ?: []);
    }

    /**
     * @return list<array{key:string,label:string}>
     */
    protected function scriptSteps(?Task $task): array
    {
        if (! $task) {
            return [];
        }

        $source = is_string($task->script_content) && trim($task->script_content) !== ''
            ? $task->script_content
            : (is_string($task->output) ? $task->output : '');

        if (trim($source) === '') {
            return [];
        }

        $labels = $this->extractScriptStepLabels($source);

        return array_map(
            fn (string $label): array => [
                'key' => 'script_'.md5($label),
                'label' => $label,
            ],
            $labels,
        );
    }

    /**
     * @return list<string>
     */
    protected function extractScriptStepLabels(string $content): array
    {
        return ProvisionStepSnapshots::extractLabels($content);
    }

    protected function lastSeenScriptStepKey(?Task $task, array $scriptSteps): ?string
    {
        if (! $task || ! is_string($task->output) || trim($task->output) === '' || $scriptSteps === []) {
            return null;
        }

        $seenLabels = $this->extractScriptStepLabels($task->output);
        if ($seenLabels === []) {
            return null;
        }

        $lastSeenLabel = $seenLabels[array_key_last($seenLabels)];

        foreach ($scriptSteps as $step) {
            if ($step['label'] === $lastSeenLabel) {
                return $step['key'];
            }
        }

        return null;
    }

    protected function scriptStepLabelForKey(?Task $task, string $key): ?string
    {
        foreach ($this->scriptSteps($task) as $step) {
            if ($step['key'] === $key) {
                return $step['label'];
            }
        }

        return null;
    }

    protected function scriptStepOutputTail(?Task $task, string $label): ?string
    {
        $output = $this->scriptStepOutput($task, $label);

        if ($output === null) {
            return null;
        }

        $lines = preg_split('/\r\n|\r|\n/', $output) ?: [];

        return implode("\n", array_slice($lines, -3));
    }

    protected function scriptStepOutput(?Task $task, string $label): ?string
    {
        if (! $task || ! is_string($task->output) || trim($task->output) === '') {
            return null;
        }

        $lines = preg_split('/\r\n|\r|\n/', $task->output) ?: [];
        $filtered = [];
        $capture = false;

        foreach ($lines as $line) {
            if (str_contains($line, ProvisionStepSnapshots::SCRIPT_STEP_PREFIX.$label)) {
                $capture = true;

                continue;
            }

            if ($capture && str_contains($line, ProvisionStepSnapshots::SCRIPT_STEP_PREFIX)) {
                break;
            }

            if ($capture && trim($line) !== '') {
                $filtered[] = $line;
            }
        }

        return $filtered === [] ? null : implode("\n", $filtered);
    }

    protected function persistedStepOutput(Server $server, string $key): ?string
    {
        $snapshot = $server->meta['provision_step_snapshots'][$key] ?? null;
        $output = is_array($snapshot) ? trim((string) ($snapshot['output'] ?? '')) : '';

        return $output !== '' ? $output : null;
    }

    protected function stepHasPersistedSnapshot(Server $server, string $key): bool
    {
        return $this->persistedStepOutput($server, $key) !== null;
    }

    protected function stepWasSkipped(?string $output): bool
    {
        if (! is_string($output) || trim($output) === '') {
            return false;
        }

        return str_contains($output, 'already installed; skipping package install.')
            || str_contains($output, 'already installed; skipping installer.')
            || str_contains($output, 'already installed; skipping package setup.');
    }
}
