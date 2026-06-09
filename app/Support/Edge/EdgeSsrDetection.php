<?php

declare(strict_types=1);

namespace App\Support\Edge;

/**
 * Determines whether runtime detection results describe a
 * server-rendered JavaScript framework repo (vs static export).
 */
final class EdgeSsrDetection
{
    /**
     * @param  array<string, mixed>  $plan
     */
    public static function planLooksLikeSsr(array $plan): bool
    {
        $framework = strtolower((string) ($plan['framework'] ?? ''));
        if (! in_array($framework, ['next', 'nuxt', 'remix', 'sveltekit'], true)) {
            return false;
        }

        $start = strtolower((string) ($plan['start_command'] ?? ''));
        if ($start === '') {
            return false;
        }

        if (str_contains($start, 'export') || str_contains($start, 'generate')) {
            return false;
        }

        $build = strtolower((string) ($plan['build_command'] ?? ''));
        if (str_contains($build, ' export') || str_contains($build, 'generate')) {
            return false;
        }

        return true;
    }
}
