<?php

namespace App\Services\ConfigRevisions\Diff;

/**
 * Renders a diff between two snapshots of the same kind. Each kind has
 * its own renderer because snapshot shapes vary (single-file vs. multi-
 * field bundles), so a single generic text diff would either be wrong
 * or unreadable.
 */
interface ConfigRevisionDiffRenderer
{
    /**
     * @param  array<string, mixed> $left  earlier/from snapshot
     * @param  array<string, mixed> $right  later/to snapshot
     * @return string a unified-diff-style block suitable for rendering in a `<pre>`
     */
    public function render(array $left, array $right): string;
}
