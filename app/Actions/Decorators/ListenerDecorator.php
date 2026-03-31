<?php

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use Illuminate\Container\Container;
use Illuminate\Routing\RouteDependencyResolverTrait;

class ListenerDecorator
{
    use DecorateActions;
    use RouteDependencyResolverTrait;

    /**
     * @var Container
     */
    protected $container;

    public function __construct($action)
    {
        $this->setAction($action);
        $this->container = new Container;
    }

    public function handle(...$arguments)
    {
        // Try asListener() method first
        if ($this->hasMethod('asListener')) {
            return $this->resolveFromArgumentsAndCall('asListener', $arguments);
        }

        // Try handle() method
        if ($this->hasMethod('handle')) {
            return $this->resolveFromArgumentsAndCall('handle', $arguments);
        }

        // Try __invoke() method
        if ($this->hasMethod('__invoke')) {
            return $this->resolveFromArgumentsAndCall('__invoke', $arguments);
        }

        return null;
    }

    public function __invoke(...$arguments)
    {
        return $this->handle(...$arguments);
    }

    public function shouldQueue(...$arguments)
    {
        if ($this->hasMethod('shouldQueue')) {
            return $this->resolveFromArgumentsAndCall('shouldQueue', $arguments);
        }

        return true;
    }

    protected function resolveFromArgumentsAndCall($method, $arguments)
    {
        $arguments = $this->resolveClassMethodDependencies(
            $arguments,
            $this->action,
            $method
        );

        return $this->action->{$method}(...array_values($arguments));
    }
}
