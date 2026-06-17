<?php

declare(strict_types=1);

namespace App\Services\DeployIntelligence;

use Illuminate\Database\Eloquent\Model;

/**
 * Plain value object returned by deploy-intelligence rules. The
 * scanner upserts each finding into deploy_intelligence_alerts —
 * (organization_id, rule_key, signature) is the dedupe key, so two
 * scans of the same condition update one row rather than spawning
 * duplicates.
 *
 * @phpstan-type AlertFindingArray array{
 *     rule_key: string,
 *     severity: string,
 *     signature: string,
 *     subject_type: ?string,
 *     subject_id: ?string,
 *     title: string,
 *     summary: ?string,
 *     payload: ?array<string, mixed>,
 * }
 */
final class AlertFinding
{
    /**
     * @param  array<string, mixed>|null  $payload
     */
    public function __construct(
        public readonly string $ruleKey,
        public readonly string $severity,
        public readonly string $signature,
        public readonly string $title,
        public readonly ?string $summary = null,
        public readonly ?Model $subject = null,
        public readonly ?array $payload = null,
    ) {}

    /**
     * @return AlertFindingArray
     */
    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'rule_key' => $this->ruleKey,
            'severity' => $this->severity,
            'signature' => $this->signature,
            'subject_type' => $this->subject?->getMorphClass(),
            'subject_id' => $this->subject?->getKey(),
            'title' => $this->title,
            'summary' => $this->summary,
            'payload' => $this->payload,
        ];
    }
}
