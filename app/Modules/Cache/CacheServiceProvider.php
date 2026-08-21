<?php

declare(strict_types=1);

namespace App\Modules\Cache;

use App\Modules\Cache\Console\SweepExpiredCacheItemsCommand;
use App\Modules\Cache\Services\PostgresCacheStore;
use Illuminate\Support\ServiceProvider;

/**
 * dply Cache — the managed cache.
 *
 * Two tiers behind one resource: a free Postgres-backed store spoken to over a
 * DynamoDB-compatible endpoint, and a dedicated Redis cluster that delegates
 * wholesale to Modules/Database. See docs/adr/dply-cache.md.
 *
 * The module deliberately owns no credential model and no signature verifier —
 * both live in the kernel, because dply Queue authenticates the same way and a
 * module must not depend on another module for its security path.
 */
class CacheServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PostgresCacheStore::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                SweepExpiredCacheItemsCommand::class,
            ]);
        }
    }
}
