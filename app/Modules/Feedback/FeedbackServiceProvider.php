<?php

declare(strict_types=1);

namespace App\Modules\Feedback;

use App\Modules\Feedback\Console\PruneFeedbackAttachmentsCommand;
use App\Modules\Feedback\Livewire\Sidebar;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Feedback module wiring (docs/adr/modular-monolith-structure.md).
 *
 * The module's classes live under App\Modules\Feedback, so Livewire's default
 * App\Livewire auto-discovery and Laravel's app/Console/Commands command
 * auto-registration no longer find them — this provider re-registers both.
 *
 * The `feedback.sidebar` alias is load-bearing: it is embedded in Blade as
 * <livewire:feedback.sidebar> and is asserted by tests/Feature/LivewireAliasGuardTest.
 * The admin Index component and the screenshot controller are referenced by
 * ::class in routes/web.php, so they need no alias registration.
 */
class FeedbackServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                PruneFeedbackAttachmentsCommand::class,
            ]);
        }
    }

    public function boot(): void
    {
        Livewire::component('feedback.sidebar', Sidebar::class);
    }
}
