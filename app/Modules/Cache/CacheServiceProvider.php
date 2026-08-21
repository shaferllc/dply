<?php

declare(strict_types=1);

namespace App\Modules\Cache;

use App\Modules\Cache\Console\SweepExpiredCacheItemsCommand;
use App\Modules\Cache\Livewire\CacheShow;
use App\Modules\Cache\Livewire\Caches;
use App\Modules\Cache\Models\ManagedCache;
use App\Modules\Cache\Services\PostgresCacheStore;
use App\Policies\ManagedCachePolicy;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
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
        Gate::policy(ManagedCache::class, ManagedCachePolicy::class);

        // Aliases for components that live in a module rather than app/Livewire.
        // Guarded by tests/Feature/LivewireAliasGuardTest.
        Livewire::component('caches', Caches::class);
        Livewire::component('cache-show', CacheShow::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                SweepExpiredCacheItemsCommand::class,
            ]);
        }
    }
}
