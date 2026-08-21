<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Support;

use App\Models\Site;
use App\Modules\Serverless\Console\ServerlessTickCommand;
use App\Modules\Serverless\Models\FunctionInvocation;

/**
 * "Is this function actually being kept warm?", flattened for a view.
 *
 * Warm start is invisible by construction: the pings leave the control
 * plane on a cron, and nothing about the toggle proves they landed. Every
 * warm path — a `keep-warm` ping, or the `schedule` tick that holds the
 * container warm as a side effect — records a `source=tick` invocation, so
 * the absence of a recent one means no ping is being sent at all. That is
 * almost always {@see ServerlessTickCommand} never running, because the
 * control plane's scheduler is stopped (cron in production,
 * `php artisan schedule:work` locally) — a failure that is otherwise
 * completely silent: the toggle reads "on" and nothing happens.
 *
 * Both warm-start surfaces (the Overview background panel and the Runtime
 * tab) read this, so they can never disagree about whether warming works.
 */
final class WarmStartStatus
{
    /**
     * How long without a tick before this calls warming broken.
     *
     * The tick is a one-minute cron, so this is five missed edges — a
     * stopped scheduler, not one run that landed late.
     */
    public const STALE_AFTER_SECONDS = 300;

    /**
     * The site's most recent warm tick, or null when none has ever landed.
     *
     * @return array{human: string, iso: string, ok: bool, durationMs: int, cold: bool, task: string, stale: bool}|null
     */
    public static function for(Site $site): ?array
    {
        $tick = FunctionInvocation::query()
            ->where('site_id', $site->id)
            ->where('source', FunctionInvocation::SOURCE_TICK)
            ->whereIn('task', ['keep-warm', 'schedule'])
            ->settled()
            ->orderByDesc('created_at')
            ->first();

        if ($tick === null || $tick->created_at === null) {
            return null;
        }

        return [
            'human' => $tick->created_at->diffForHumans(),
            'iso' => $tick->created_at->toIso8601String(),
            'ok' => (bool) $tick->success,
            'durationMs' => (int) $tick->duration_ms,
            'cold' => (bool) $tick->cold,
            'task' => (string) $tick->task,
            'stale' => $tick->created_at->lt(now()->subSeconds(self::STALE_AFTER_SECONDS)),
        ];
    }
}
