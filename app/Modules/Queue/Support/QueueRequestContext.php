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

    public function organizationId(): ?string
    {
        return $this->namespace->organization_id;
    }

    public function allows(string $scope): bool
    {
        return $this->credential->allows($scope);
    }
}
