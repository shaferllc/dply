<?php

declare(strict_types=1);

namespace App\Support\Deployment;

final readonly class SiteResourceBinding
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        public string $type,
        public string $mode,
        public bool $required,
        public string $status,
        public string $source,
        public ?string $name = null,
        public array $config = [],
    ) {}

    /**
     * @return array{
     *     type: string,
     *     mode: string,
     *     required: bool,
     *     status: string,
     *     source: string,
     *     name: ?string,
     *     config: array<string, mixed>
     * }
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'mode' => $this->mode,
            'required' => $this->required,
            'status' => $this->status,
            'source' => $this->source,
            'name' => $this->name,
            'config' => $this->config,
        ];
    }
}
