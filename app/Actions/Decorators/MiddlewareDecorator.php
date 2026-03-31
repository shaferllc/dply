<?php

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use Closure;
use Illuminate\Http\Request;

class MiddlewareDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    public function handle(Request $request, Closure $next)
    {
        // Try asMiddleware() method first
        if ($this->hasMethod('asMiddleware')) {
            $result = $this->callMethod('asMiddleware', [$request, $next]);

            // Ensure we return a response
            return $result ?? $next($request);
        }

        // Try handle() method
        if ($this->hasMethod('handle')) {
            $result = $this->callMethod('handle', [$request, $next]);

            return $result ?? $next($request);
        }

        // Default: pass request to next middleware
        return $next($request);
    }
}
