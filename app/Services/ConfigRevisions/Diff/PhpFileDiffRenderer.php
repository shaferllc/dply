<?php

namespace App\Services\ConfigRevisions\Diff;

use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\UnifiedDiffOutputBuilder;

/**
 * Diff renderer for single-file snapshots shaped as
 *   { "path": "...", "content": "..." }
 *
 * Used by php_cli_ini / php_fpm_ini / php_pool and any future single-
 * file kinds (supervisor program, .env, systemd unit, ...).
 */
class PhpFileDiffRenderer implements ConfigRevisionDiffRenderer
{
    public function render(array $left, array $right): string
    {
        $leftContent = is_string($left['content'] ?? null) ? $left['content'] : '';
        $rightContent = is_string($right['content'] ?? null) ? $right['content'] : '';

        return self::renderUnifiedDiff($leftContent, $rightContent);
    }

    /**
     * Helper exposed so other single-file renderers can reuse the same
     * unified-diff output without duplicating the Differ wiring.
     */
    public static function renderUnifiedDiff(string $from, string $to): string
    {
        if ($from === $to) {
            return '';
        }

        $builder = new UnifiedDiffOutputBuilder('', false);
        $differ = new Differ($builder);

        return $differ->diff($from, $to);
    }
}
