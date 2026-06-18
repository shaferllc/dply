<?php

namespace App\Actions\Concerns;

trait DecorateActions
{
    protected mixed $action = null;

    public function setAction(mixed $action): self
    {
        $this->action = $action;

        return $this;
    }

    protected function hasTrait(string $trait): bool
    {
        return in_array($trait, class_uses_recursive($this->action));
    }

    protected function hasProperty(string $property): bool
    {
        return property_exists($this->action, $property);
    }

    protected function getProperty(string $property): mixed
    {
        return $this->action->{$property};
    }

    protected function hasMethod(string $method): bool
    {
        return isset($this->action) && method_exists($this->action, $method);
    }

    /**
     * @param  array<int, mixed>  $parameters
     */
    protected function callMethod(string $method, array $parameters = []): mixed
    {
        return call_user_func_array([$this->action, $method], $parameters);
    }

    /**
     * @param  array<string, mixed>  $extraArguments
     */
    protected function resolveAndCallMethod(string $method, array $extraArguments = []): mixed
    {
        return app()->call([$this->action, $method], $extraArguments);
    }

    /**
     * @param  array<int, mixed>  $methodParameters
     */
    protected function fromActionMethod(string $method, array $methodParameters = [], mixed $default = null): mixed
    {
        return $this->hasMethod($method)
            ? $this->callMethod($method, $methodParameters)
            : value($default);
    }

    protected function fromActionProperty(string $property, mixed $default = null): mixed
    {
        return $this->hasProperty($property)
            ? $this->getProperty($property)
            : value($default);
    }

    /**
     * @param  array<int, mixed>  $methodParameters
     */
    protected function fromActionMethodOrProperty(string $method, string $property, mixed $default = null, array $methodParameters = []): mixed
    {
        if ($this->hasMethod($method)) {
            return $this->callMethod($method, $methodParameters);
        }

        if ($this->hasProperty($property)) {
            return $this->getProperty($property);
        }

        return value($default);
    }
}
