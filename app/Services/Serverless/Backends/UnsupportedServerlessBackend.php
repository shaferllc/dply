<?php

declare(strict_types=1);

namespace App\Services\Serverless\Backends;

use App\Models\FunctionAction;

/**
 * The backend returned for hosts that do not yet implement triggers or
 * sequences — currently AWS Lambda, until the EventBridge and Step Functions
 * implementations land.
 *
 * It satisfies both contracts so callers never have to null-check a backend;
 * every operation simply reports an explanatory failure rather than throwing.
 */
class UnsupportedServerlessBackend implements ServerlessSequenceBackend, ServerlessTriggerBackend
{
    public function __construct(private readonly string $reason = 'This serverless host does not support triggers or sequences yet.') {}

    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    public function provision(FunctionAction $action): array
    {
        return ['ok' => false, 'error' => $this->reason, 'trigger' => null];
    }

    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    public function remove(FunctionAction $action): array
    {
        return ['ok' => false, 'error' => $this->reason];
    }

    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    public function deploy(FunctionAction $sequence): array
    {
        return ['ok' => false, 'error' => $this->reason];
    }
}
