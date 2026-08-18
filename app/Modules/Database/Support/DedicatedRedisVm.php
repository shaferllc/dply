<?php

declare(strict_types=1);

namespace App\Modules\Database\Support;

use App\Models\Server;
use App\Support\Servers\DedicatedCacheServerProvisionConfig;

/**
 * Static facts for the "dedicated Redis server" placement: a brand-new VM
 * whose only job is Redis, using the existing `redis_server` install
 * profile and {@see DedicatedCacheServerProvisionConfig}
 * recipe. Eligibility matches a dedicated database VM — cloud API + credential.
 */
final class DedicatedRedisVm
{
    /** @return list<string> */
    public static function supportedEngines(): array
    {
        return ['redis'];
    }

    public static function eligible(Server $server): bool
    {
        return DedicatedDatabaseVm::eligible($server);
    }
}
