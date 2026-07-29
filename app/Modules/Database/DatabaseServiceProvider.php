<?php

declare(strict_types=1);

namespace App\Modules\Database;

use App\Modules\Database\Backends\DatabaseRouter;
use Illuminate\Support\ServiceProvider;

/**
 * Database module wiring (docs/adr/modular-monolith-structure.md).
 *
 * Owns the managed-database backend abstraction — the {@see DatabaseRouter}
 * and its {@see \App\Modules\Database\Backends\DatabaseBackend} implementations
 * (DigitalOcean Managed Databases today; Vultr / RDS / serverless vendors to
 * come). This is the engine that lets a single "Provision new database" modal
 * place a database either on the site's own box (the kernel ServerDatabase
 * path) or on a co-located managed cluster, without the presentation shell
 * knowing which provider answered.
 *
 * The on-box ServerDatabase lifecycle deliberately stays in the kernel — only
 * the managed-cluster capability lives here. The Eloquent record for a managed
 * database is still App\Models\CloudDatabase (a cosmetic rename to
 * ManagedDatabase is a later, isolated follow-up); this module orchestrates it.
 */
class DatabaseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DatabaseRouter::class);
    }
}
