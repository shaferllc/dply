<?php

declare(strict_types=1);

namespace App\Modules\Serverless\Services\Backends;

use App\Models\FunctionAction;

/**
 * Provider-neutral contract for scheduling a serverless action.
 *
 * DigitalOcean Functions implements this with OpenWhisk alarm-feed triggers;
 * AWS Lambda implements it with EventBridge schedules. {@see ServerlessBackendResolver}
 * picks the right implementation for an action's host.
 */
interface ServerlessTriggerBackend
{
    /**
     * Create or update the action's scheduled trigger. With no enabled
     * schedule this tears any existing trigger down — idempotent against
     * the desired state.
     *
     * @return array{ok: bool, error: ?string, trigger: ?string}
     */
    public function provision(FunctionAction $action): array;

    /**
     * Tear down the action's scheduled trigger.
     *
     * @return array{ok: bool, error: ?string}
     */
    public function remove(FunctionAction $action): array;
}
