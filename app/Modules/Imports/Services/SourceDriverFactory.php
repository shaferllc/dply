<?php

declare(strict_types=1);

namespace App\Modules\Imports\Services;

use App\Models\ProviderCredential;
use App\Modules\Imports\Services\Forge\ForgeImportDriver;
use App\Modules\Imports\Services\Ploi\PloiImportDriver;
use RuntimeException;

/**
 * Resolves the right ImportDriver for a ProviderCredential, branching on
 * provider. Lets handlers stay source-agnostic — they ask for "the driver
 * for this credential" without having to know whether that's Ploi, Forge,
 * or any future addition. The b→c table promotion later can plug a single
 * inventory-source table into the same factory shape.
 */
class SourceDriverFactory
{
    public function for(ProviderCredential $credential): ImportDriver
    {
        return match ($credential->provider) {
            'ploi' => PloiImportDriver::for($credential),
            'forge' => ForgeImportDriver::for($credential),
            default => throw new RuntimeException(
                sprintf('No ImportDriver registered for provider %s', $credential->provider)
            ),
        };
    }
}
