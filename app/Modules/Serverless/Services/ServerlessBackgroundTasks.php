<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Services;

use App\Models\Site;

/**
 * Which background tasks tick for a serverless site.
 *
 * dply ticks a function once a minute per enabled task: `schedule` runs the
 * app's scheduler, `queue` drains its queue. Each has its own flag in
 * `site.meta.serverless`, plus the legacy bundled `background_enabled` that
 * predates the split and still answers "is anything ticking at all?".
 *
 * That mirror is the reason this lives in one place: the Schedule page, the
 * Workers page, and both API surfaces all write it, and a copy that forgets
 * to consider the *other* task silently stops the site's background work.
 */
final class ServerlessBackgroundTasks
{
    /** Tasks with an operator-facing toggle, mapped to their stored flag. */
    private const FLAGS = [
        'schedule' => 'scheduler_enabled',
        'queue' => 'queue_worker_enabled',
    ];

    /**
     * Is this task ticking? Falls back to the legacy bundled flag so a site
     * configured before the split keeps the operator's previous choice.
     */
    public function enabled(Site $site, string $task): bool
    {
        $config = $this->config($site);

        return (bool) ($config[$this->flag($task)] ?? $config['background_enabled'] ?? false);
    }

    /**
     * Flip one task, keeping the legacy bundled flag true iff either task is
     * on — callers that still read the old key see the right state.
     */
    public function setEnabled(Site $site, string $task, bool $enabled): void
    {
        $config = $this->config($site);
        $other = $task === 'queue' ? 'schedule' : 'queue';
        $otherOn = (bool) ($config[$this->flag($other)] ?? $config['background_enabled'] ?? false);

        $config[$this->flag($task)] = $enabled;
        $config['background_enabled'] = $enabled || $otherOn;

        $meta = is_array($site->meta) ? $site->meta : [];
        $meta['serverless'] = $config;

        $site->update(['meta' => $meta]);
        $site->refresh();
    }

    private function flag(string $task): string
    {
        return self::FLAGS[$task] ?? throw new \InvalidArgumentException("Unknown background task [{$task}].");
    }

    /**
     * @return array<string, mixed>
     */
    private function config(Site $site): array
    {
        $meta = is_array($site->meta) ? $site->meta : [];

        return is_array($meta['serverless'] ?? null) ? $meta['serverless'] : [];
    }
}
