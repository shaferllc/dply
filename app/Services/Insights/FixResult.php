<?php

namespace App\Services\Insights;

final class FixResult
{
    public function __construct(
        public bool $ok,
        public string $output = '',
        public ?string $errorMessage = null,
    ) {}

    public static function success(string $output = ''): self
    {
        return new self(ok: true, output: $output);
    }

    public static function failure(string $errorMessage, string $output = ''): self
    {
        return new self(ok: false, output: $output, errorMessage: $errorMessage);
    }
}
