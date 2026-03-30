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
            /** @var Router $router */
            $router = $this;
            $registrar = new ActionResourceRegistrar($router);

            return new PendingResourceRegistration(
                $registrar, $name, $namespace, $options
            );
        };
    }
}
