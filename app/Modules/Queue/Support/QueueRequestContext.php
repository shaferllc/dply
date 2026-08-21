<?php

declare(strict_types=1);

namespace App\Modules\Queue\Support;

use App\Modules\Queue\Models\QueueCredential;
use App\Modules\Queue\Models\QueueNamespace;

/**
 * The authenticated identity behind one queue API request.
 *
 * The tenancy model in one object. `namespace` comes from the credential and
 * is **never** read from the request body or path — a controller cannot
 * accidentally trust a client-supplied namespace id, because there is nowhere
 * to get one from. This codebase has no global scopes, so centralising it here
 * is what keeps every endpoint honest.
 */
final readonly class QueueRequestContext
{
    public function __construct(
        public QueueNamespace $namespace,
        public QueueCredential $credential,
        public int $requestsPerMinute,
    ) {}

    public function namespaceId(): string
    {
        return $this->namespace->id;
    }

    /**
     * The allowance for polling reads, which is deliberately larger than the
     * tier's write rate.
     *
     * An empty ReceiveMessage costs one indexed query and changes nothing, so
     * pricing it the same as a push or a delete would mean a customer buys
     * throughput and then spends it on workers finding an empty queue. The
     * multiplier is what lets a fleet poll attentively without eating the
     * budget the drain itself needs.
     */
    public function pollsPerMinute(): int
    {
        $multiplier = max(1, (int) config('queue_service.rate.poll_multiplier', 4));

        return $this->requestsPerMinute * $multiplier;
    }

    public function allows(string $scope): bool
    {
        return $this->credential->allows($scope);
    }
}
