<?php

declare(strict_types=1);

namespace App\Services\Scaffold;

/**
 * Snapshot of a single pipeline step recorded under
 * Site.meta.scaffold.steps[]. The journey UI (PR 7) reads from here
 * to render each row.
 */
final class ScaffoldStep
{
    public const STATE_PENDING = 'pending';

    public const STATE_RUNNING = 'running';

    public const STATE_COMPLETED = 'completed';

    public const STATE_FAILED = 'failed';

    public const STATE_SKIPPED = 'skipped';

    /**
     * @return array{key: string, label: string, state: string}
     */
    public static function pending(string $key, string $label): array
    {
        return ['key' => $key, 'label' => $label, 'state' => self::STATE_PENDING];
    }
}
