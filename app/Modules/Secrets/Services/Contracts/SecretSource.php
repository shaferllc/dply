<?php

declare(strict_types=1);

namespace App\Modules\Secrets\Services\Contracts;

use App\Modules\Secrets\Services\Scope;

/**
 * Produces the plaintext bytes to be escrowed for a given scope. Implementations
 * are the only place that knows how to gather a particular kind of secret
 * (platform .env, a pg_dump, an org env bundle, …).
 */
interface SecretSource
{
    /** Stable source name used in the blob key (e.g. platform-env, db-dump). */
    public function name(): string;

    /** Gather the plaintext to escrow. May be large (DB dumps). */
    public function gather(Scope $scope): string;
}
