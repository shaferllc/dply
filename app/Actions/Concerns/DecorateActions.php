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

    protected function setProperty(string $property, mixed $value): void
    {
        $this->action->{$property} = $value;
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
        // Reflection when the method really exists, because a decorator calls
        // the action from outside its scope. Every hook the framework documents
        // -- getAuthGuard(), getAuthRedirectRoute(), handleUnauthenticated(),
        // see the examples in AsAuthenticated -- is documented as `protected`,
        // and call_user_func_array() cannot reach those: the call falls through
        // to the base class's __call(), which throws BadMethodCallException for
        // a method that plainly exists. hasMethod() uses method_exists(), which
        // ignores visibility, so the hook passes the guard and then dies.
        //
        // The call_user_func_array() fallback is kept for names that do NOT
        // exist as real methods, which is the case __call() legitimately serves.
        if (method_exists($this->action, $method)) {
            return (new \ReflectionMethod($this->action, $method))
                ->invokeArgs($this->action, $parameters);
        }

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
