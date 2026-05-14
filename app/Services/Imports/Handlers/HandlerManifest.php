<?php

declare(strict_types=1);

namespace App\Services\Imports\Handlers;

use App\Services\Imports\StepHandler;

/**
 * Canonical list of every step handler in the system. Iterating this in the
 * service provider (AppServiceProvider::register) keeps the registry binding
 * declarative — adding a new handler is one line here plus the class itself.
 */
final class HandlerManifest
{
    /**
     * @return list<class-string<StepHandler>>
     */
    public static function all(): array
    {
        return [
            // Phase 3a — fully implemented.
            PushSshKeyHandler::class,
            RevokeSshKeyHandler::class,
            EligibilityScanHandler::class,
            FreezeSnapshotHandler::class,

            // Phase 3b stubs — fail loudly until SSH-side implementations land.
            CloneRepoHandler::class,
            CopyEnvHandler::class,
            DumpDatabaseHandler::class,
            RestoreDatabaseHandler::class,
            RecreateCronsHandler::class,
            RecreateDaemonsHandler::class,
            RecreateSchedulerHandler::class,
            SetupSslHandler::class,
            CutoverMaintenanceOnHandler::class,
            CutoverDbDeltaHandler::class,
            CutoverDnsSwapHandler::class,
            CutoverWebhookSwapHandler::class,
            CutoverSmokeTestHandler::class,
        ];
    }
}
