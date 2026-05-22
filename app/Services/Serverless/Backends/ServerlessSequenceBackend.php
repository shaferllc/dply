<?php

declare(strict_types=1);

namespace App\Services\Serverless\Backends;

use App\Models\FunctionAction;

/**
 * Provider-neutral contract for deploying a serverless composition.
 *
 * DigitalOcean Functions implements this with OpenWhisk sequence actions;
 * AWS Lambda implements it with Step Functions state machines.
 * {@see ServerlessBackendResolver} picks the right implementation.
 */
interface ServerlessSequenceBackend
{
    /**
     * Deploy a sequence action — a `kind=sequence` {@see FunctionAction} —
     * to its host.
     *
     * @return array{ok: bool, error: ?string}
     */
    public function deploy(FunctionAction $sequence): array;
}
