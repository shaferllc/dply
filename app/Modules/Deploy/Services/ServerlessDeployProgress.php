<?php

declare(strict_types=1);

namespace App\Modules\Deploy\Services;

use App\Models\Site;
use App\Models\SiteDeployment;
use App\Modules\Serverless\Exceptions\ServerlessDeployCancelledException;
use App\Modules\Serverless\Support\ServerlessCustomerCopy;
use App\Support\DeployLogRedactor;
use App\Support\DeployLogSanitizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Records fine-grained serverless deploy progress so the deploy journey can
 * show live sub-steps — the FaaS counterpart to the per-step list on a VM
 * provision journey.
 *
 * A DigitalOcean Functions deploy runs as one synchronous job, so there is
 * no phase runner emitting structured results. This recorder lets the
 * artifact builder and action deployer mark sub-steps as they go: each call
 * upserts a step into the running deployment's `phase_results['serverless']`
 * list, which the polling journey renders.
 *
 * It locates the deployment from the Site itself (there is exactly one
 * running deploy per site — the deploy lock guarantees it), so callers need
 * no SiteDeployment handle. When nothing is running — e.g. the builder is
 * exercised directly in a test — every call is a silent no-op.
 */
class ServerlessDeployProgress
{
    /** phase_results key the journey reads sub-steps from. */
    public const PHASE = 'serverless';

    public const STATE_PENDING = 'pending';

    public const STATE_ACTIVE = 'active';

    public const STATE_DONE = 'done';

    public const STATE_FAILED = 'failed';

    /**
     * Always-present sub-steps the journey seeds as soon as a deploy row
     * exists, so the operator sees the pipeline before checkout starts.
     * Optional steps (adapter, hooks, commands) insert in {@see STEP_ORDER}.
     *
     * @var list<array{key: string, label: string}>
     */
    public const CATALOG = [
        ['key' => 'checkout', 'label' => 'Checking out the repository'],
        ['key' => 'detect', 'label' => 'Detecting runtime'],
        ['key' => 'dependencies', 'label' => 'Installing dependencies'],
        ['key' => 'package', 'label' => 'Packaging the artifact'],
        ['key' => 'upload', 'label' => 'Pushing the action'],
        ['key' => 'verify', 'label' => 'Verifying the function'],
    ];

    /**
     * @var array<string, int>
     */
    private const STEP_ORDER = [
        'checkout' => 10,
        'hooks_before' => 20,
        'detect' => 30,
        'adapter' => 40,
        'environment' => 50,
        'dependencies' => 60,
        'hooks_after' => 70,
        'commands' => 80,
        'optimize' => 85,
        'assets' => 88,
        'package' => 90,
        'upload' => 100,
        'actions' => 110,
        'verify' => 120,
        'hooks_activate' => 130,
    ];

    /** Cache key prefix holding a pending cancel request (the deployment id). */
    private const CANCEL_PREFIX = 'serverless-deploy-cancel:';

    private const LOG_FLUSH_SECONDS = 0.75;

    private const LOG_MAX_CHARS = 200_000;

    /**
     * Process-local log tail so every caller in this job (builder, deployer,
     * checkout callback) shares one buffer. Laravel resolves a new instance
     * per injection; static state is what keeps streamed composer/npm lines
     * from living in a buffer that never gets flushed by the other instance.
     *
     * @var array<string, array{buffer: string, flushed_at: float}>
     */
    private static array $logState = [];

    /**
     * Flag the running deploy for cancellation. The next step checkpoint
     * aborts it. Keyed by deployment id so a stale request can never kill a
     * later deploy of the same function.
     */
    public function requestCancel(Site $site, string $deploymentId): void
    {
        Cache::put(self::CANCEL_PREFIX.$site->id, $deploymentId, now()->addMinutes(15));
    }

    /**
     * Abort the deploy if the operator has requested cancellation. Called at
     * each step boundary, so cancellation lands between steps (it cannot
     * interrupt an in-flight composer install or upload mid-stream).
     */
    public function checkpoint(Site $site): void
    {
        $requested = Cache::get(self::CANCEL_PREFIX.$site->id);
        if ($requested === null) {
            return;
        }

        $deployment = $this->runningDeployment($site);
        if ($deployment !== null && $requested === $deployment->id) {
            Cache::forget(self::CANCEL_PREFIX.$site->id);
            throw new ServerlessDeployCancelledException('Deploy cancelled by operator.');
        }
    }

    /**
     * Human label for the dependency/build command the journey shows while
     * composer / npm / pip is running — not the generic "Installing
     * dependencies" that hid a 3-minute composer install.
     */
    public static function dependenciesLabel(string $command): string
    {
        $hasComposer = (bool) preg_match('/(^|[;&|]\s*|&&\s*|\|\|\s*)composer(\s|$)/', $command);
        $hasNode = (bool) preg_match('/(^|[;&|]\s*|&&\s*|\|\|\s*)(npm|npx|node|pnpm|yarn|bun)(\s|$)/', $command);
        $hasPython = (bool) preg_match('/(^|[;&|]\s*|&&\s*|\|\|\s*)(pip3?|poetry|pipenv)(\s|$)/', $command);
        $hasGo = (bool) preg_match('/(^|[;&|]\s*|&&\s*|\|\|\s*)go\s+build(\s|$)/', $command);

        return match (true) {
            $hasComposer && $hasNode => 'Installing Composer and Node dependencies',
            $hasComposer => 'Installing Composer dependencies',
            $hasNode => 'Installing Node dependencies',
            $hasPython => 'Installing Python dependencies',
            $hasGo => 'Building the Go binary',
            default => 'Installing dependencies',
        };
    }

    /**
     * Write the pending catalog as soon as the running deploy row exists, so
     * Journey has something specific to show during preflight / before the
     * first `active()` call. No-op when steps are already recorded.
     */
    public function seed(Site $site): void
    {
        $deployment = $this->runningDeployment($site);
        if ($deployment === null || $deployment->phaseSteps(self::PHASE) !== []) {
            return;
        }

        $steps = [];
        foreach (self::CATALOG as $item) {
            $steps[] = [
                'key' => $item['key'],
                'label' => $item['label'],
                'state' => self::STATE_PENDING,
                'detail' => '',
                'ok' => false,
                'started_at' => null,
                'finished_at' => null,
                'duration_ms' => null,
            ];
        }

        $deployment->recordPhaseResults(self::PHASE, $steps);
    }

    /**
     * Append runner output to the in-flight deploy log so Journey's poll
     * can show composer / git / npm chatter while the step is still running.
     * Writes are throttled; call {@see flushLog()} at step boundaries.
     */
    public function appendLog(Site $site, string $chunk): void
    {
        $plain = DeployLogSanitizer::sanitize($chunk);
        if ($plain === '') {
            return;
        }

        $id = (string) $site->id;
        $state = self::$logState[$id] ?? ['buffer' => '', 'flushed_at' => 0.0];
        $buffer = $state['buffer'];
        if ($buffer !== '' && ! str_ends_with($buffer, "\n") && ! str_starts_with($plain, "\n")) {
            $buffer .= "\n";
        }
        $state['buffer'] = $buffer.$plain.(str_ends_with($plain, "\n") ? '' : "\n");
        self::$logState[$id] = $state;

        if ((microtime(true) - $state['flushed_at']) >= self::LOG_FLUSH_SECONDS) {
            $this->flushLog($site);
        }
    }

    public function flushLog(Site $site): void
    {
        $id = (string) $site->id;
        $state = self::$logState[$id] ?? null;
        $buffer = is_array($state) ? (string) ($state['buffer'] ?? '') : '';
        if ($buffer === '') {
            return;
        }

        $deployment = $this->runningDeployment($site);
        if ($deployment === null) {
            unset(self::$logState[$id]);

            return;
        }

        $existing = (string) ($deployment->log_output ?? '');
        $combined = trim($existing === '' ? $buffer : $existing."\n".$buffer);
        $combined = ServerlessCustomerCopy::neutralize(DeployLogRedactor::redact($combined));
        if (strlen($combined) > self::LOG_MAX_CHARS) {
            $combined = ltrim(substr($combined, -self::LOG_MAX_CHARS));
        }

        $deployment->update(['log_output' => $combined]);
        self::$logState[$id] = ['buffer' => '', 'flushed_at' => microtime(true)];
    }

    public function active(Site $site, string $key, string $label, string $detail = ''): void
    {
        $this->checkpoint($site);
        $this->step($site, $key, $label, self::STATE_ACTIVE, $detail);
    }

    public function done(Site $site, string $key, string $label, string $detail = ''): void
    {
        $this->step($site, $key, $label, self::STATE_DONE, $detail);
    }

    /**
     * Upsert one sub-step into the running deployment's serverless phase.
     *
     * Each step carries timing: `active` stamps `started_at`; `done` /
     * `failed` stamp `finished_at` and compute `duration_ms` against the
     * step's own start — so the journey can show how long each step took.
     */
    public function step(Site $site, string $key, string $label, string $state, string $detail = ''): void
    {
        $deployment = $this->runningDeployment($site);

        if ($deployment === null) {
            return;
        }

        $this->flushLog($site);
        $deployment->refresh();

        $steps = $deployment->phaseSteps(self::PHASE);

        $existing = null;
        $index = null;
        foreach ($steps as $i => $step) {
            if (($step['key'] ?? null) === $key) {
                $existing = $step;
                $index = $i;
                break;
            }
        }

        $now = now();
        $startedAt = is_string($existing['started_at'] ?? null) ? $existing['started_at'] : null;
        if ($startedAt === null && $state === self::STATE_ACTIVE) {
            $startedAt = $now->toIso8601String();
        }

        $finishedAt = null;
        $durationMs = null;
        if (in_array($state, [self::STATE_DONE, self::STATE_FAILED], true)) {
            $finishedAt = $now->toIso8601String();
            if ($startedAt !== null) {
                $durationMs = max(0, (int) round(Carbon::parse($startedAt)->diffInMilliseconds($now)));
            }
        }

        $entry = [
            'key' => $key,
            'label' => $label,
            'state' => $state,
            'detail' => $detail,
            'ok' => $state === self::STATE_DONE,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'duration_ms' => $durationMs,
        ];

        if ($index === null) {
            $steps[] = $entry;
        } else {
            $steps[$index] = $entry;
        }

        $deployment->recordPhaseResults(self::PHASE, $this->sortedSteps($steps));

        $wasActive = ($existing['state'] ?? null) === self::STATE_ACTIVE;
        if ($state === self::STATE_ACTIVE && ! $wasActive) {
            $marker = '[dply] '.$label;
            if (trim($detail) !== '') {
                $marker .= "\n".$detail;
            }
            $this->appendLog($site, $marker);
            $this->flushLog($site);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return list<array<string, mixed>>
     */
    private function sortedSteps(array $steps): array
    {
        usort($steps, static function (array $a, array $b): int {
            $left = self::STEP_ORDER[(string) ($a['key'] ?? '')] ?? 1000;
            $right = self::STEP_ORDER[(string) ($b['key'] ?? '')] ?? 1000;

            return $left <=> $right;
        });

        return array_values($steps);
    }

    private function runningDeployment(Site $site): ?SiteDeployment
    {
        return SiteDeployment::query()
            ->where('site_id', $site->id)
            ->where('status', SiteDeployment::STATUS_RUNNING)
            ->latest('created_at')
            ->first();
    }
}
