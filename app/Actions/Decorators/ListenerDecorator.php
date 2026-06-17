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

    public function __construct(mixed $action)
    {
        $this->setAction($action);
        $this->container = new Container;
    }

    public function handle(mixed ...$arguments): mixed
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

    public function __invoke(mixed ...$arguments): mixed
    {
        return $this->handle(...$arguments);
    }

    public function shouldQueue(mixed ...$arguments): bool
    {
        if ($this->hasMethod('shouldQueue')) {
            return $this->resolveFromArgumentsAndCall('shouldQueue', $arguments);
        }

        return true;
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    protected function resolveFromArgumentsAndCall(string $method, array $arguments): mixed
    {
        $arguments = $this->resolveClassMethodDependencies(
            $arguments,
            $this->action,
            $method
        );

        return $this->action->{$method}(...array_values($arguments));
    }
}
