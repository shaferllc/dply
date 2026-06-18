<?php

declare(strict_types=1);

namespace App\Modules\Realtime;

use App\Modules\Realtime\Console\CollectRealtimeUsageCommand;
use App\Modules\Realtime\Console\RealtimeDoctorCommand;
use App\Modules\Realtime\Console\RealtimeSetupCommand;
use App\Modules\Realtime\Livewire\Realtime;
use App\Modules\Realtime\Livewire\RealtimeAppShow;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Realtime module wiring (docs/adr/modular-monolith-structure.md).
 *
 * The managed Pusher-compatible relay feature: Actions, the Cloudflare-backed
 * Services, provisioning Job, usage/doctor/setup commands, and the two full-page
 * org pages. Commands re-registered here; full-page components registered under
 * their original auto-derived names. The RealtimeApp model stays in app/Models
 * and its billing observer stays wired in AppServiceProvider (Site/RealtimeApp
 * ::observe) with a repointed reference.
 */
class RealtimeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CollectRealtimeUsageCommand::class,
                RealtimeDoctorCommand::class,
                RealtimeSetupCommand::class,
            ]);
        }
    }

    public function boot(): void
    {
        Livewire::component('organizations.realtime', Realtime::class);
        Livewire::component('organizations.realtime-app-show', RealtimeAppShow::class);
    }
}
