<?php

declare(strict_types=1);

namespace App\Support\Deployment;

final readonly class DeploymentSecret
{
    public function __construct(
        public string $key,
        public string $value,
        public string $scope,
        public string $source,
        public ?string $environment,
        public string $classification,
        public bool $isSecret,
    ) {}

    /**
     * @return array{
     *     key: string,
     *     value: string,
     *     scope: string,
     *     source: string,
     *     environment: ?string,
     *     classification: string,
     *     is_secret: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'value' => $this->value,
            'scope' => $this->scope,
            'source' => $this->source,
            'environment' => $this->environment,
            'classification' => $this->classification,
            'is_secret' => $this->isSecret,
        ];
    }
}
