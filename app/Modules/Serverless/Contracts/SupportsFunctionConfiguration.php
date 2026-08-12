<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Contracts;

use App\Modules\Serverless\Support\FunctionConfiguration;

/**
 * A backend that can apply HTTP-exposure and parameter configuration to an
 * already-deployed function, without shipping new code.
 *
 * Deploy applies the same configuration inline; this contract exists for the
 * edit case — an operator toggling CORS or rotating the endpoint secret
 * should take effect immediately rather than waiting for the next deploy.
 */
interface SupportsFunctionConfiguration
{
    /**
     * @param  array<string, mixed>  $context  Backend-specific addressing (namespace, package, credentials).
     * @return array{ok: bool, error: ?string, data: mixed}
     */
    public function applyFunctionConfiguration(string $name, FunctionConfiguration $configuration, array $context = []): array;
}
