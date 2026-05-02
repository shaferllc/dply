<?php

namespace App\Livewire\Servers\Concerns;

use App\Support\Servers\MaintenanceWindow;

/**
 * Helpers for Livewire components on a server workspace that need to gate disruptive
 * actions (firewall apply, supervisor restart-all, etc.) on the server's configured
 * maintenance window.
 *
 * Server-side soft block: when a window is configured and the current time falls
 * outside it, the action posts an explanatory toast and refuses unless the caller
 * passes `$override = true`. The UI sets that flag from a confirm dialog, so users
 * can still proceed with an explicit click.
 */
trait GuardsDisruptiveActions
{
    /**
     * Return false (and post a toast) when the action is blocked by the maintenance window.
     */
    protected function disruptiveActionAllowed(string $actionLabel, bool $override): bool
    {
        $window = MaintenanceWindow::forServer($this->server);
        if (! $window->enabled() || $window->containsNow() || $override) {
            return true;
        }

        $this->toastError(__(
            'Skipped — :action is outside this server\'s maintenance window (:window). Click Override to run anyway.',
            ['action' => $actionLabel, 'window' => $window->summary()]
        ));

        return false;
    }

    /**
     * Confirm-message string for `wire:confirm`. Returns an empty string when no window
     * is configured or we're already inside it (no extra prompt needed). Outside the
     * window it returns a sentence with the window summary so the user can override.
     */
    protected function disruptiveConfirmMessage(string $actionLabel): string
    {
        $window = MaintenanceWindow::forServer($this->server);
        if (! $window->enabled() || $window->containsNow()) {
            return '';
        }

        return __(':action runs OUTSIDE the configured maintenance window (:window). Continue?', [
            'action' => $actionLabel,
            'window' => $window->summary(),
        ]);
    }
}
