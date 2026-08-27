<?php

declare(strict_types=1);

namespace App\Support\Sites;

/**
 * Is this Supervisor program a queue worker?
 *
 * The single boundary between the Queue page and the Workers page. Both ask
 * this class rather than pattern-matching independently: two lists built from
 * two nearly-identical regexes is how a program ends up on both pages with two
 * sets of start/stop buttons, or on neither.
 *
 * Deliberately narrow. A program is a queue worker only when its command is
 * recognisably a queue consumer — anything else is a daemon and belongs on
 * Workers, because wrongly hiding someone's daemon from the page they manage it
 * on is worse than showing a queue worker there.
 */
final class QueueWorkerClassifier
{
    /**
     * Command fragments that make a process a queue consumer.
     *
     * `queue:listen` is included though it is long deprecated: sites still run
     * it, and it consumes jobs, so the Queue page has to account for it.
     *
     * @var list<string>
     */
    private const MARKERS = [
        'queue:work',
        'queue:listen',
        'horizon',
    ];

    public static function isQueueWorker(?string $command): bool
    {
        $command = strtolower(trim((string) $command));

        if ($command === '') {
            return false;
        }

        foreach (self::MARKERS as $marker) {
            if (str_contains($command, $marker)) {
                // `horizon:snapshot` is a metrics cron, not a consumer, and
                // `horizon:terminate` / `horizon:pause` are one-shot controls.
                // Only the long-running forms consume jobs.
                if ($marker === 'horizon' && ! self::isHorizonConsumer($command)) {
                    continue;
                }

                return true;
            }
        }

        return false;
    }

    /**
     * The queue name a `--queue=` flag declares, or null when the command does
     * not name one (the app's default queue, which only the app can resolve).
     */
    public static function queueNameFrom(?string $command): ?string
    {
        $command = trim((string) $command);

        // The quote has to be consumed, not excluded: `--queue='default'` is the
        // form Supervisor programs actually carry, and a character class that
        // merely forbids quotes matches zero characters against it.
        if ($command === '' || preg_match('/--queue[=\s]+["\']?([^\s"\']+)/i', $command, $m) !== 1) {
            return null;
        }

        // A worker can name several: `--queue=high,default` is one process
        // draining both, in priority order.
        return trim($m[1], '\'"') ?: null;
    }

    private static function isHorizonConsumer(string $command): bool
    {
        foreach (['horizon:work', 'horizon:supervisor'] as $consumer) {
            if (str_contains($command, $consumer)) {
                return true;
            }
        }

        // Bare `artisan horizon` is the master process: it owns every worker on
        // the box, so the Queue page is where it belongs.
        return (bool) preg_match('/artisan[\'"\s]+horizon(\s|$)/', $command);
    }
}
