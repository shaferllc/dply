<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs\ApplyRemediationHandlerAllowListTest;

use App\Models\Server;
use App\Models\Site;
use App\Services\ConsoleActions\ConsoleEmitter;
use App\Modules\Remediations\Services\RemediationActionInterface;

/** A handler that *passes* the interface check but is NOT in the allow-list. */
class RogueHandler implements RemediationActionInterface
{
    public static bool $applied = false;

    public function apply(?Server $server, ?Site $site, ?string $userId, ConsoleEmitter $emit): ?string
    {
        self::$applied = true;

        return null;
    }
}
