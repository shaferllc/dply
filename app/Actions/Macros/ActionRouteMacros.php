<?php

namespace App\Actions\Macros;

use App\Actions\Routing\ActionResourceRegistrar;
use Illuminate\Routing\PendingResourceRegistration;
use Illuminate\Routing\Router;

class ActionRouteMacros
{
    public function resourceActions(): callable
    {
        return function (string $name, string $namespace = 'App\Actions', array $options = []): PendingResourceRegistration {
            // Resolve the router from the container rather than leaning on $this:
            // Route::mixin() rebinds this closure to the Router at runtime, but
            // statically $this is the mixin class, so the old `@var Router $this`
            // contradicted the native type. Same instance either way.
            $registrar = new ActionResourceRegistrar(app(Router::class));

            return new PendingResourceRegistration(
                $registrar, $name, $namespace, $options
            );
        };
    }
}
