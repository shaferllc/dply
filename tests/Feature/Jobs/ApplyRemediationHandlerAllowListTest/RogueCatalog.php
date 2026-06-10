<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs\ApplyRemediationHandlerAllowListTest;

use App\Services\Remediations\RemediationCatalog;

/** Catalog that hands back the rogue handler yet reports an empty allow-list. */
class RogueCatalog extends RemediationCatalog
{
    public function action(string $code, string $actionKey): ?array
    {
        return ['key' => $actionKey, 'label' => 'Rogue', 'handler' => RogueHandler::class];
    }

    public function handlerClasses(): array
    {
        return []; // RogueHandler is deliberately absent.
    }
}
