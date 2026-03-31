<?php

namespace App\Services\Insights;

final class InsightCandidate
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $insightKey,
        public string $dedupeHash,
        public string $severity,
        public string $title,
        public ?string $body = null,
        public array $meta = [],
    ) {}
}
