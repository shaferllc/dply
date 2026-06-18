<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Support;

/**
 * Personalized serverless glue recipe for one org.
 *
 * @phpstan-type StepArray array{text: string, href: string|null, link_label: string|null}
 * @phpstan-type ResourceArray array{kind: string, label: string, href: string|null, meta: string|null}
 */
final readonly class ServerlessGlueRecipe
{
    /**
     * @param  array<string, mixed> $steps
     * @param  array<string, mixed> $resources
     * @param  array<string, mixed> $gaps
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $summary,
        public ?string $docSlug,
        public bool $available,
        public ?string $unavailableReason,
        public array $steps,
        public array $resources,
        public array $gaps,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'summary' => $this->summary,
            'doc_slug' => $this->docSlug,
            'available' => $this->available,
            'unavailable_reason' => $this->unavailableReason,
            'steps' => $this->steps,
            'resources' => $this->resources,
            'gaps' => $this->gaps,
        ];
    }
}
