<?php

declare(strict_types=1);

namespace App\Support\Launch;

/**
 * Recommended layer in a multi-engine full-stack deployment plan.
 *
 * @phpstan-type LaunchParams array<string, scalar|null>
 */
final readonly class FullStackLayer
{
    /**
     * @param  LaunchParams  $launchParams
     */
    public function __construct(
        public string $id,
        public string $engine,
        public string $label,
        public string $description,
        public string $status,
        public string $launchRoute,
        public array $launchParams,
        public ?string $repoRoot = null,
        public ?string $runtimeMode = null,
        public ?string $framework = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'engine' => $this->engine,
            'label' => $this->label,
            'description' => $this->description,
            'status' => $this->status,
            'launch_route' => $this->launchRoute,
            'launch_params' => $this->launchParams,
            'repo_root' => $this->repoRoot,
            'runtime_mode' => $this->runtimeMode,
            'framework' => $this->framework,
        ];
    }
}
