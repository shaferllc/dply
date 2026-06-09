<?php

namespace Tests\Concerns;

use App\Services\SshConnectionFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSshConnectionFactory;

/**
 * Prevents feature tests from making real SSH connections or running
 * Server::created provisioning jobs inline on the sync test queue.
 *
 * Applied to all tests under tests/Feature via tests/Pest.php.
 */
trait FakesRemoteServerAccess
{
    protected function setUpFakesRemoteServerAccess(): void
    {
        Queue::fake();

        $this->app->singleton(FakeSshConnectionFactory::class, fn () => new FakeSshConnectionFactory);
        $this->app->instance(
            SshConnectionFactory::class,
            $this->app->make(FakeSshConnectionFactory::class),
        );

        // After RefreshDatabase rolls back, drop the connection so a killed run
        // does not leave idle-in-transaction sessions blocking migrate:fresh.
        $this->beforeApplicationDestroyed(function (): void {
            try {
                DB::disconnect(config('database.default'));
            } catch (\Throwable) {
                // Best-effort cleanup only.
            }
        });
    }
}
