<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Site;

/**
 * When a queue is unhealthy enough to tell someone.
 *
 * Queues are not records — they are names that appear in a snapshot and vanish
 * when nothing pushes to them — so their rules live on the site: one set of
 * defaults, overridable per queue name. No table of config for entities that do
 * not exist, and no stale rows outliving the queues they name.
 *
 * The default set is deliberately small: jobs waiting with nothing draining
 * them, which is always wrong and needs no tuning, and a burst of failures —
 * the shape of a deploy that broke every job, the most common real queue
 * incident. A queue that is merely deep might be perfectly healthy at 3pm and
 * alarming at 3am, so depth and age thresholds stay opt-in rather than
 * shipping a number dply invented.
 */
final class SiteQueueAlertRules
{
    /** Below this, "sustained" would fire on a single spike between sweeps. */
    public const MIN_SUSTAINED_MINUTES = 5;

    /** New failures within the window that page by default. */
    public const DEFAULT_FAILURES_AT_LEAST = 10;

    public function __construct(
        public readonly bool $enabled,
        /** Alert when pending stays above this. Null disables the rule. */
        public readonly ?int $pendingOver,
        /** How long the backlog must hold before it counts; also the failure window. */
        public readonly int $sustainedMinutes,
        /** Alert when the oldest waiting job is older than this, in seconds. Null disables. */
        public readonly ?int $oldestOverSeconds,
        /** Alert when jobs are waiting and no worker is draining them. */
        public readonly bool $noWorker,
        /** Alert when at least this many jobs fail within the window. Null disables. */
        public readonly ?int $failuresAtLeast = self::DEFAULT_FAILURES_AT_LEAST,
    ) {}

    public static function defaults(): self
    {
        return new self(
            enabled: true,
            pendingOver: null,
            sustainedMinutes: 10,
            oldestOverSeconds: null,
            noWorker: true,
            failuresAtLeast: self::DEFAULT_FAILURES_AT_LEAST,
        );
    }

    /** The rules in force for one queue: site defaults, with any override applied. */
    public static function for(Site $site, string $queue): self
    {
        $stored = (array) data_get($site->meta, 'queue_alerts', []);
        $base = array_replace(
            self::defaults()->toArray(),
            (array) ($stored['defaults'] ?? []),
        );

        $override = (array) data_get($stored, 'queues.'.$queue, []);

        return self::fromArray(array_replace($base, $override), (bool) ($stored['enabled'] ?? true));
    }

    /** @param  array<string, mixed>  $values */
    public static function fromArray(array $values, bool $enabled = true): self
    {
        $int = static fn (mixed $v): ?int => is_numeric($v) && (int) $v > 0 ? (int) $v : null;

        return new self(
            enabled: $enabled,
            pendingOver: $int($values['pending_over'] ?? null),
            // A window shorter than two sweeps cannot observe anything
            // "sustained" — the reading it would judge is a single sample.
            sustainedMinutes: max(self::MIN_SUSTAINED_MINUTES, (int) ($values['sustained_minutes'] ?? 10)),
            oldestOverSeconds: $int($values['oldest_over_s'] ?? null),
            noWorker: (bool) ($values['no_worker'] ?? true),
            // Missing means "never set" and takes the default; a stored null
            // means someone switched the rule off, which must stay off.
            failuresAtLeast: array_key_exists('failures_at_least', $values)
                ? $int($values['failures_at_least'])
                : self::DEFAULT_FAILURES_AT_LEAST,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'pending_over' => $this->pendingOver,
            'sustained_minutes' => $this->sustainedMinutes,
            'oldest_over_s' => $this->oldestOverSeconds,
            'no_worker' => $this->noWorker,
            'failures_at_least' => $this->failuresAtLeast,
        ];
    }

    /** Nothing to evaluate — skip the queries entirely. */
    public function isSilent(): bool
    {
        return ! $this->enabled
            || ($this->pendingOver === null && $this->oldestOverSeconds === null && ! $this->noWorker && $this->failuresAtLeast === null);
    }
}
