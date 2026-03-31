<?php

declare(strict_types=1);

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use Illuminate\Support\Facades\Auth;

/**
 * Decorator that requires authentication before action execution.
 *
 * This decorator automatically checks authentication using Laravel's Auth
 * before executing the action. If authentication fails, it calls handleUnauthenticated().
 */
class AuthenticatedDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    public function handle(...$arguments)
    {
        $guard = $this->getAuthGuard();

        if (! Auth::guard($guard)->check()) {
            $this->handleUnauthenticated();
        }

        if (empty($arguments) && $this->hasMethod('asController')) {
            return $this->callMethod('asController');
        }

        return $this->callMethod('handle', $arguments);
    }

    protected function handleUnauthenticated(): void
    {
        if ($this->hasMethod('handleUnauthenticated')) {
            $this->callMethod('handleUnauthenticated');

            return;
        }

        if (request()->expectsJson()) {
            abort(401, 'Unauthenticated');
        }

        $redirectRoute = $this->getAuthRedirectRoute();

        if ($redirectRoute) {
            redirect()->route($redirectRoute)->send();
            exit;
        }

        abort(401, 'Unauthenticated');
    }

    protected function getAuthGuard(): string
    {
        if ($this->hasMethod('getAuthGuard')) {
            return $this->callMethod('getAuthGuard');
        }

        if ($this->hasProperty('authGuard')) {
            return $this->getProperty('authGuard');
        }

        return config('auth.defaults.guard', 'web');
    }

    protected function getAuthRedirectRoute(): ?string
    {
        if ($this->hasMethod('getAuthRedirectRoute')) {
            return $this->callMethod('getAuthRedirectRoute');
        }

        return 'login';
    }
}
