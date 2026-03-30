<?php

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;

/**
 * Password Confirmation Decorator
 *
 * Automatically requires password confirmation before allowing action execution.
 * This decorator intercepts handle() calls and verifies the user has confirmed
 * their password within a configurable timeout period.
 *
 * Features:
 * - Automatic password confirmation checking
 * - Session-based confirmation tracking
 * - Configurable timeout period
 * - Custom redirect routes
 * - JSON/HTML request handling
 * - Per-user confirmation tracking
 *
 * How it works:
 * 1. When an action uses AsPasswordConfirmation, PasswordConfirmationDesignPattern recognizes it
 * 2. ActionManager wraps the action with PasswordConfirmationDecorator
 * 3. When handle() is called, the decorator:
 *    - Checks if user is authenticated
 *    - Checks if password was confirmed within timeout
 *    - Throws 403 or redirects if confirmation required
 *    - Executes the action if confirmed
 *    - Adds confirmation metadata to result
 *
 * Password Confirmation Metadata:
 * The result will include a `_password_confirmation` property with:
 * - `checked`: Whether password confirmation check was performed
 * - `confirmed`: Whether password was confirmed
 * - `timeout`: Confirmation timeout in seconds
 */
class PasswordConfirmationDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    /**
     * Execute the action with password confirmation checking.
     *
     * @param  mixed  ...$arguments
     * @return mixed
     *
     * @throws HttpResponseException If confirmation required
     */
    public function handle(...$arguments)
    {
        $user = Auth::user();

        if (! $user) {
            abort(401, 'Unauthenticated');
        }

        if (! $this->isPasswordConfirmed($user)) {
            $this->handlePasswordConfirmationRequired();
        }

        // Execute the action
        $result = $this->action->handle(...$arguments);

        // Add password confirmation metadata to result
        if (is_object($result)) {
            $result->_password_confirmation = [
                'checked' => true,
                'confirmed' => true,
                'timeout' => $this->getPasswordConfirmationTimeout(),
            ];
        }

        return $result;
    }

    /**
     * Make the decorator callable.
     *
     * @param  mixed  ...$arguments
     * @return mixed
     */
    public function __invoke(...$arguments)
    {
        return $this->handle(...$arguments);
    }

    /**
     * Check if password is confirmed for the user.
     *
     * @param  mixed  $user
     */
    protected function isPasswordConfirmed($user): bool
    {
        $sessionKey = $this->getPasswordConfirmationSessionKey($user);
        $timeout = $this->getPasswordConfirmationTimeout();

        if (! session()->has($sessionKey)) {
            return false;
        }

        $confirmedAt = session($sessionKey);

        // Check if confirmation has expired
        if (time() - $confirmedAt > $timeout) {
            session()->forget($sessionKey);

            return false;
        }

        return true;
    }

    /**
     * Handle password confirmation required.
     *
     *
     * @throws HttpResponseException
     */
    protected function handlePasswordConfirmationRequired(): void
    {
        if ($this->hasMethod('handlePasswordConfirmationRequired')) {
            $this->callMethod('handlePasswordConfirmationRequired');

            return;
        }

        if (request()->expectsJson()) {
            abort(403, 'Password confirmation required.');
        }

        // Redirect to password confirmation page
        $redirectRoute = $this->getPasswordConfirmationRedirectRoute();

        if ($redirectRoute) {
            redirect()->route($redirectRoute)->send();
            exit;
        }

        abort(403, 'Password confirmation required.');
    }

    /**
     * Get the session key for password confirmation.
     *
     * @param  mixed  $user
     */
    protected function getPasswordConfirmationSessionKey($user): string
    {
        if ($this->hasMethod('getPasswordConfirmationSessionKey')) {
            return $this->callMethod('getPasswordConfirmationSessionKey', [$user]);
        }

        return 'password_confirmed_at_'.$user->id;
    }

    /**
     * Get password confirmation timeout in seconds.
     */
    protected function getPasswordConfirmationTimeout(): int
    {
        if ($this->hasMethod('getPasswordConfirmationTimeout')) {
            return (int) $this->callMethod('getPasswordConfirmationTimeout');
        }

        if ($this->hasProperty('passwordConfirmationTimeout')) {
            return (int) $this->getProperty('passwordConfirmationTimeout');
        }

        return 10800; // Default: 3 hours
    }

    /**
     * Get the redirect route for password confirmation.
     */
    protected function getPasswordConfirmationRedirectRoute(): ?string
    {
        if ($this->hasMethod('getPasswordConfirmationRedirectRoute')) {
            return $this->callMethod('getPasswordConfirmationRedirectRoute');
        }

        return 'password.confirm';
    }
}
