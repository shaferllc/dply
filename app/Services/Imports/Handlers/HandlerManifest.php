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
            // Server-level handlers.
            PushSshKeyHandler::class,
            RevokeSshKeyHandler::class,
            EligibilityScanHandler::class,
            CollectManualReviewHandler::class,

            // Per-site staging handlers.
            FreezeSnapshotHandler::class,
            CreateTargetSiteHandler::class,
            CloneRepoHandler::class,
            CopyEnvHandler::class,
            DumpDatabaseHandler::class,
            RestoreDatabaseHandler::class,
            RecreateCronsHandler::class,
            RecreateDaemonsHandler::class,
            RecreateSchedulerHandler::class,
            SetupSslHandler::class,

            // Per-site cutover handlers.
            CutoverMaintenanceOnHandler::class,
            CutoverDbDeltaHandler::class,
            CutoverDnsSwapHandler::class,
            CutoverWebhookSwapHandler::class,
            CutoverSmokeTestHandler::class,
        ];
    }
}
