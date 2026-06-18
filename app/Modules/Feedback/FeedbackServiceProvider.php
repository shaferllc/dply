<?php

declare(strict_types=1);

namespace App\Modules\Feedback;

use App\Modules\Feedback\Console\PruneFeedbackAttachmentsCommand;
use App\Modules\Feedback\Livewire\Admin\Index as AdminFeedbackIndex;
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
 * The admin Index, though referenced by ::class in routes/web.php, is a full-page
 * component that ALSO needs registration: route rendering derives a name from the
 * class, which fails once the class lives outside App\Livewire. The screenshot
 * controller is a plain controller (FQCN in routes) and needs nothing.
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

        // Full-page route component (Route::livewire in routes/web.php): registered
        // under its original auto-derived name so Livewire resolves it by class at
        // render time — route:list does not exercise this path.
        Livewire::component('admin.feedback.index', AdminFeedbackIndex::class);
    }
}
