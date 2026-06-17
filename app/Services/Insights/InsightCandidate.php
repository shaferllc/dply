<?php

namespace App\Services\Insights;

use App\Models\InsightFinding;

final class InsightCandidate
{
    /**
     * @param  array<string, mixed> $meta
     * @param  string  $kind  'problem' (default) or 'suggestion'. Suggestions skip notifications
     *                        and render in a separate UI section. See {@see InsightFinding}.
     */
    public function __construct(
        public string $insightKey,
        public string $dedupeHash,
        public string $severity,
        public string $title,
        public ?string $body = null,
        public array $meta = [],
        public string $kind = InsightFinding::KIND_PROBLEM,
    ) {}
}
