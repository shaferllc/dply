<?php

declare(strict_types=1);

namespace App\Modules\Remediations\Services;

use App\Models\Server;
use App\Models\Site;
use App\Services\ConsoleActions\ConsoleEmitter;

/**
 * A class-backed remediation action (the `handler` path in config/remediations).
 * Used when a fix can't be a declarative bash script — e.g. regenerating a site's
 * nginx vhost from the model via a job, or anything needing preflight/branching.
 */
interface RemediationActionInterface
{
    /**
     * Run the fix. Return null on success, or a short human-readable error string
     * on failure. Implementations must not throw — surface failures via the return
     * value and the emitter.
     */
    public function apply(?Server $server, ?Site $site, ?string $userId, ConsoleEmitter $emit): ?string;
}
