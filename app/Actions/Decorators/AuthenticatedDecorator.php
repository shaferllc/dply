<?php

declare(strict_types=1);

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use Illuminate\Http\Exceptions\HttpResponseException;
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

    public function __construct(mixed $action)
    {
        $this->setAction($action);
    }

    /**
     * @param  mixed  ...$arguments
     * @return mixed
     */
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
            // Throw, never send()+exit. exit terminates the PHP process
            // outright: under a queue worker or console command it kills the
            // whole process mid-job, and under a test runner it takes the run
            // down with no output at all -- which is exactly how this decorator
            // silenced the entire App suite (ISS-018). It also skips terminable
            // middleware and the rest of the response lifecycle.
            //
            // HttpResponseException is the framework's supported way to return
            // a response from deep in the stack: in an HTTP request the handler
            // unwraps it into this same redirect, and everywhere else it is an
            // ordinary catchable exception.
            throw new HttpResponseException(redirect()->route($redirectRoute));
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
