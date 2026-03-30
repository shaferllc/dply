<?php

namespace App\Actions\Concerns;

use Illuminate\Support\Facades\Auth;

/**
 * Requires password confirmation before action execution.
 *
 * Provides password confirmation capabilities for actions, requiring users to
 * confirm their password before executing sensitive operations. Confirmation
 * is tracked in session with a configurable timeout.
 *
 * How it works:
 * - PasswordConfirmationDesignPattern recognizes actions using AsPasswordConfirmation
 * - ActionManager wraps the action with PasswordConfirmationDecorator
 * - When handle() is called, the decorator:
 *    - Checks if user is authenticated
 *    - Checks if password was confirmed within timeout period
 *    - Redirects to confirmation page or throws 403 if not confirmed
 *    - Executes the action if confirmed
 *    - Adds confirmation metadata to result
 *
 * Benefits:
 * - Automatic password confirmation checking
 * - Session-based confirmation tracking
 * - Configurable timeout period
 * - Custom redirect routes
 * - JSON/HTML request handling
 * - Per-user confirmation tracking
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * PasswordConfirmationDecorator, which automatically wraps actions and checks
 * password confirmation. This follows the same pattern as AsTimeout, AsThrottle,
 * and other decorator-based concerns.
 *
 * Password Confirmation Metadata:
 * The result will include a `_password_confirmation` property with:
 * - `checked`: Whether password confirmation check was performed
 * - `confirmed`: Whether password was confirmed
 * - `timeout`: Confirmation timeout in seconds
 *
 * Password Confirmation Flow:
 * 1. User attempts to execute action
 * 2. Decorator checks if password was confirmed recently
 * 3. If not confirmed, redirects to password confirmation page
 * 4. User enters password on confirmation page
 * 5. Confirmation page sets session key with timestamp
 * 6. User can now execute the action (within timeout period)
 *
 * @example
 * // Basic usage - delete account with password confirmation:
 * class DeleteAccount extends Actions
 * {
 *     use AsPasswordConfirmation;
 *
 *     public function handle(): void
 *     {
 *         Auth::user()->delete();
 *     }
 *
 *     public function getPasswordConfirmationTimeout(): int
 *     {
 *         return 10800; // 3 hours in seconds
 *     }
 * }
 *
 * // Usage:
 * DeleteAccount::run();
 * // Automatically requires password confirmation before execution
 * // Redirects to password confirmation page if not confirmed
 * @example
 * // Custom timeout for sensitive operations:
 * class TransferFunds extends Actions
 * {
 *     use AsPasswordConfirmation;
 *
 *     public function handle(Account $from, Account $to, float $amount): void
 *     {
 *         // Transfer funds
 *     }
 *
 *     public function getPasswordConfirmationTimeout(): int
 *     {
 *         return 300; // 5 minutes - shorter timeout for financial operations
 *     }
 * }
 *
 * // Usage:
 * TransferFunds::run($fromAccount, $toAccount, 1000.00);
 * // Requires password confirmation within last 5 minutes
 * @example
 * // Custom redirect route:
 * class ChangeEmail extends Actions
 * {
 *     use AsPasswordConfirmation;
 *
 *     public function handle(string $newEmail): void
 *     {
 *         Auth::user()->update(['email' => $newEmail]);
 *     }
 *
 *     public function getPasswordConfirmationRedirectRoute(): string
 *     {
 *         return 'settings.password.confirm';
 *     }
 * }
 *
 * // Usage:
 * ChangeEmail::run($newEmail);
 * // Redirects to custom password confirmation route
 * @example
 * // Using properties for configuration:
 * class SensitiveAction extends Actions
 * {
 *     use AsPasswordConfirmation;
 *
 *     // Configure via properties
 *     public int $passwordConfirmationTimeout = 1800; // 30 minutes
 *
 *     public function handle(): void
 *     {
 *         // Sensitive operation
 *     }
 * }
 *
 * // Usage:
 * $action = SensitiveAction::make();
 * $action->passwordConfirmationTimeout = 600; // Override for this instance
 * $action->handle();
 * @example
 * // Custom unauthorized handling:
 * class CustomAction extends Actions
 * {
 *     use AsPasswordConfirmation;
 *
 *     public function handle(): void
 *     {
 *         // Custom operation
 *     }
 *
 *     public function handlePasswordConfirmationRequired(): void
 *     {
 *         // Custom handling instead of default redirect
 *         \Log::warning('Password confirmation required', [
 *             'user_id' => auth()->id(),
 *             'action' => get_class($this),
 *         ]);
 *
 *         // Return JSON response for API
 *         if (request()->expectsJson()) {
 *             abort(403, [
 *                 'error' => 'password_confirmation_required',
 *                 'message' => 'Please confirm your password to continue.',
 *                 'redirect' => route('password.confirm'),
 *             ]);
 *         }
 *
 *         // Redirect for web requests
 *         redirect()->route('password.confirm')->send();
 *         exit;
 *     }
 * }
 * @example
 * // Password confirmation in API endpoints:
 * class ApiSensitiveOperation extends Actions
 * {
 *     use AsPasswordConfirmation;
 *
 *     public function handle(): array
 *     {
 *         return ['success' => true];
 *     }
 *
 *     public function handlePasswordConfirmationRequired(): void
 *     {
 *         abort(403, [
 *             'error' => 'password_confirmation_required',
 *             'message' => 'Password confirmation required for this operation.',
 *             'timeout' => $this->getPasswordConfirmationTimeout(),
 *         ]);
 *     }
 * }
 *
 * // Usage:
 * ApiSensitiveOperation::run();
 * // Returns JSON error response for API requests
 * @example
 * // Password confirmation with Livewire:
 * class LivewireSensitiveAction extends Actions
 * {
 *     use AsPasswordConfirmation;
 *
 *     public function handle(): void
 *     {
 *         // Sensitive operation
 *     }
 *
 *     public function getPasswordConfirmationRedirectRoute(): string
 *     {
 *         return 'livewire.password.confirm';
 *     }
 * }
 *
 * // Livewire Component:
 * class SensitiveOperation extends Component
 * {
 *     public function performAction(): void
 *     {
 *         LivewireSensitiveAction::run();
 *         // Password confirmation is automatically checked
 *     }
 *
 *     public function render(): View
 *     {
 *         return view('livewire.sensitive-operation');
 *     }
 * }
 *
 * // Password confirmation works seamlessly with Livewire
 * @example
 * // Password confirmation metadata in results:
 * class TrackedAction extends Actions
 * {
 *     use AsPasswordConfirmation;
 *
 *     public function handle(): array
 *     {
 *         return ['success' => true];
 *     }
 * }
 *
 * // Usage:
 * $result = TrackedAction::run();
 *
 * // Access password confirmation metadata:
 * if (isset($result->_password_confirmation)) {
 *     $checked = $result->_password_confirmation['checked'];
 *     $confirmed = $result->_password_confirmation['confirmed'];
 *     $timeout = $result->_password_confirmation['timeout'];
 * }
 * // $result->_password_confirmation = ['checked' => true, 'confirmed' => true, 'timeout' => 10800]
 * @example
 * // Combining with other decorators:
 * class ComprehensiveAction extends Actions
 * {
 *     use AsPasswordConfirmation;
 *     use AsPermission;
 *     use AsRetry;
 *
 *     public function handle(): void
 *     {
 *         // Operation that needs password confirmation, permission, and retry
 *     }
 *
 *     public function getRequiredPermissions(): array
 *     {
 *         return ['sensitive.operation'];
 *     }
 *
 *     public function getPasswordConfirmationTimeout(): int
 *     {
 *         return 1800; // 30 minutes
 *     }
 * }
 *
 * // Usage:
 * ComprehensiveAction::run();
 * // Combines password confirmation, permission checking, and retry decorators
 * @example
 * // Password confirmation page implementation:
 * // In your password confirmation route/controller:
 * class PasswordConfirmationController extends Controller
 * {
 *     public function show()
 *     {
 *         return view('auth.confirm-password');
 *     }
 *
 *     public function confirm(Request $request)
 *     {
 *         $request->validate([
 *             'password' => 'required|password',
 *         ]);
 *
 *         // Set password confirmation in session
 *         session()->put('password_confirmed_at_'.auth()->id(), time());
 *
 *         // Redirect back to intended action
 *         return redirect()->intended();
 *     }
 * }
 *
 * // Usage in action:
 * class DeleteAccount extends Actions
 * {
 *     use AsPasswordConfirmation;
 *
 *     public function handle(): void
 *     {
 *         Auth::user()->delete();
 *     }
 * }
 *
 * // After user confirms password, they can execute the action
 * @example
 * // Different timeouts for different operations:
 * class QuickAction extends Actions
 * {
 *     use AsPasswordConfirmation;
 *
 *     public function handle(): void
 *     {
 *         // Quick operation
 *     }
 *
 *     public function getPasswordConfirmationTimeout(): int
 *     {
 *         return 300; // 5 minutes
 *     }
 * }
 *
 * class LongRunningAction extends Actions
 * {
 *     use AsPasswordConfirmation;
 *
 *     public function handle(): void
 *     {
 *         // Long operation
 *     }
 *
 *     public function getPasswordConfirmationTimeout(): int
 *     {
 *         return 21600; // 6 hours
 *     }
 * }
 *
 * // Different actions can have different confirmation timeouts
 * @example
 * // Password confirmation with custom session key:
 * class CustomSessionAction extends Actions
 * {
 *     use AsPasswordConfirmation;
 *
 *     public function handle(): void
 *     {
 *         // Custom operation
 *     }
 *
 *     public function getPasswordConfirmationSessionKey($user): string
 *     {
 *         // Custom session key per action
 *         return 'custom_confirmation_'.get_class($this).'_'.$user->id;
 *     }
 * }
 *
 * // Each action can have its own confirmation session key
 */
trait AsPasswordConfirmation
{
    // This trait is now just a marker trait.
    // The actual password confirmation logic is handled by PasswordConfirmationDecorator
    // which is automatically applied via PasswordConfirmationDesignPattern.

    /**
     * Get password confirmation timeout in seconds.
     * Override this method to customize timeout.
     */
    protected function getPasswordConfirmationTimeout(): int
    {
        if ($this->hasProperty('passwordConfirmationTimeout')) {
            return (int) $this->getProperty('passwordConfirmationTimeout');
        }

        return 10800; // Default: 3 hours
    }

    /**
     * Get the redirect route for password confirmation.
     * Override this method to customize redirect route.
     */
    protected function getPasswordConfirmationRedirectRoute(): ?string
    {
        return 'password.confirm';
    }

    /**
     * Handle password confirmation required.
     * Override this method for custom unauthorized handling.
     */
    protected function handlePasswordConfirmationRequired(): void
    {
        if (request()->expectsJson()) {
            abort(403, 'Password confirmation required.');
        }

        $redirectRoute = $this->getPasswordConfirmationRedirectRoute();

        if ($redirectRoute) {
            redirect()->route($redirectRoute)->send();
            exit;
        }

        abort(403, 'Password confirmation required.');
    }

    /**
     * Get the session key for password confirmation.
     * Override this method to customize session key.
     *
     * @param  mixed  $user
     */
    protected function getPasswordConfirmationSessionKey($user): string
    {
        return 'password_confirmed_at_'.$user->id;
    }
}
