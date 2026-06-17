<?php

declare(strict_types=1);

namespace App\Support\Launch;

/**
 * Multi-engine architecture recommendation for a single Git repository.
 */
final readonly class FullStackLaunchPlan
{
    /**
     * @param  array<string, mixed> $layers
     * @param  array<string, mixed> $wiringHints
     * @param  array<string, mixed> $reasons
     * @param  array<string, mixed> $warnings
     */
    public function __construct(
        public string $repo,
        public string $branch,
        public bool $isMonorepo,
        public array $layers,
        public array $wiringHints,
        public array $reasons,
        public array $warnings = [],
    ) {}

    public function hasLayer(string $id): bool
    {
        foreach ($this->layers as $layer) {
            if ($layer->id === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'repo' => $this->repo,
            'branch' => $this->branch,
            'is_monorepo' => $this->isMonorepo,
            'layers' => array_map(fn (FullStackLayer $layer): array => $layer->toArray(), $this->layers),
            'wiring_hints' => $this->wiringHints,
            'reasons' => $this->reasons,
            'warnings' => $this->warnings,
        ];
    }
}
