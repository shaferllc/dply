<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Jobs;

use App\Modules\Serverless\Contracts\SupportsAsyncInvocation;
use App\Modules\Serverless\Models\FunctionInvocation;
use App\Modules\Serverless\Services\ServerlessProvisionerLocator;
use App\Modules\Serverless\Support\ActivationRecord;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Collects the outcome of an async invocation.
 *
 * An activation record does not exist until the activation finishes, so the
 * platform answers 404 for the whole time the function is running. That is
 * the normal case here, not an error — the job re-dispatches itself on a
 * gentle backoff until the record appears or the function's own timeout has
 * demonstrably passed.
 *
 * Self-dispatch rather than queue retries: a retry would re-run with the same
 * delay policy the queue was configured with, and the wait here has to scale
 * with the *function's* timeout (up to 15 minutes), which the queue knows
 * nothing about.
 */
class PollFunctionActivationJob implements ShouldQueue
{
    use Queueable;

    /** One HTTP GET; a genuine failure is re-driven by the next attempt. */
    public int $tries = 1;

    public int $timeout = 60;

    /** Seconds between polls. Short enough to feel live, long enough not to hammer. */
    private const INTERVAL_SECONDS = 5;

    /** Give the platform this much slack past the function's own timeout. */
    private const GRACE_SECONDS = 60;

    public function __construct(public string $invocationId, public int $attempt = 1) {}

    public function handle(ServerlessProvisionerLocator $provisioners): void
    {
        $invocation = FunctionInvocation::query()->with('site.server')->find($this->invocationId);

        if (! $invocation instanceof FunctionInvocation || $invocation->state !== FunctionInvocation::STATE_PENDING) {
            return;
        }

        $site = $invocation->site;
        $activationId = (string) $invocation->activation_id;

        if ($site === null || $activationId === '') {
            $this->giveUp($invocation, 'The invocation has no activation to poll.');

            return;
        }

        $provisioner = $provisioners->forSite($site);
        if (! $provisioner instanceof SupportsAsyncInvocation) {
            $this->giveUp($invocation, 'The function host can no longer be reached.');

            return;
        }

        $result = $provisioner->fetchActivation($activationId, $provisioners->contextForSite($site));

        if ($result['pending']) {
            $this->reschedule($invocation);

            return;
        }

        if (! $result['ok'] || ! is_array($result['activation'])) {
            // A transport error is worth another attempt; only a persistent
            // one should end the invocation as failed.
            $this->reschedule($invocation, (string) ($result['error'] ?? 'The activation could not be fetched.'));

            return;
        }

        $invocation->forceFill(ActivationRecord::fromArray($result['activation'])->toRowAttributes())->save();
    }

    /**
     * Queue the next poll, unless the function's own timeout has passed —
     * past that point the activation is never going to appear.
     */
    private function reschedule(FunctionInvocation $invocation, ?string $lastError = null): void
    {
        $timeoutMs = $invocation->site?->serverlessLimits()['timeout'] ?? 60000;
        $budget = (int) ceil($timeoutMs / 1000) + self::GRACE_SECONDS;
        $maxAttempts = max(3, (int) ceil($budget / self::INTERVAL_SECONDS));

        if ($this->attempt >= $maxAttempts) {
            $this->giveUp($invocation, $lastError ?? 'The function did not report a result before its timeout elapsed.');

            return;
        }

        self::dispatch($this->invocationId, $this->attempt + 1)
            ->delay(now()->addSeconds(self::INTERVAL_SECONDS));
    }

    private function giveUp(FunctionInvocation $invocation, string $reason): void
    {
        Log::warning('serverless.activation.poll_abandoned', [
            'invocation_id' => $invocation->id,
            'activation_id' => $invocation->activation_id,
            'attempts' => $this->attempt,
            'reason' => $reason,
        ]);

        $invocation->forceFill([
            'state' => FunctionInvocation::STATE_FAILED,
            'success' => false,
            'result_excerpt' => $reason,
        ])->save();
    }
}
