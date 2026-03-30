<?php

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;

/**
 * Decorator that automatically converts array arguments to DTO objects.
 *
 * This decorator wraps actions and converts array arguments to DTO instances
 * based on type hints in the handle method or inferred DTO class names.
 */
class DTODecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    public function handle(...$arguments)
    {
        // Convert array arguments to DTOs
        $arguments = $this->convertArgumentsToDTOs($arguments);

        // Call the action's handle method with converted arguments
        return $this->callMethod('handle', $arguments);
    }

    /**
     * Convert array arguments to DTO objects.
     */
    protected function convertArgumentsToDTOs(array $arguments): array
    {
        if (empty($arguments)) {
            return $arguments;
        }

        // Get handle method reflection to check parameter types
        try {
            $reflection = new \ReflectionMethod($this->action, 'handle');
            $parameters = $reflection->getParameters();
        } catch (\ReflectionException $e) {
            // If handle method doesn't exist, return arguments as-is
            return $arguments;
        }

        foreach ($arguments as $index => $argument) {
            // Only convert arrays
            if (! is_array($argument)) {
                continue;
            }

            // Try to get DTO class from parameter type hint
            $dtoClass = $this->getDTOClassForParameter($parameters[$index] ?? null);

            // Fallback to inferred DTO class for first argument
            if (! $dtoClass && $index === 0) {
                $dtoClass = $this->getDTOClass();
            }

            if ($dtoClass && class_exists($dtoClass)) {
                $arguments[$index] = $this->createDTO($argument, $dtoClass);
            }
        }

        return $arguments;
    }

    /**
     * Get DTO class for a specific parameter.
     */
    protected function getDTOClassForParameter(?\ReflectionParameter $parameter): ?string
    {
        if (! $parameter) {
            return null;
        }

        $type = $parameter->getType();

        if (! $type instanceof \ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $className = $type->getName();

        return class_exists($className) ? $className : null;
    }

    /**
     * Get the DTO class name.
     *
     * First checks for a custom getDTOClass method, then tries to infer
     * from the action class name (e.g., CreateUser -> CreateUserDTO).
     */
    protected function getDTOClass(): ?string
    {
        if ($this->hasMethod('getDTOClass')) {
            return $this->callMethod('getDTOClass');
        }

        // Try to infer from class name (e.g., CreateUser -> CreateUserDTO)
        $className = class_basename($this->action);
        $dtoClass = "App\\DTOs\\{$className}DTO";

        if (class_exists($dtoClass)) {
            return $dtoClass;
        }

        // Try alternative namespace
        $dtoClass = "App\\DataTransferObjects\\{$className}DTO";

        return class_exists($dtoClass) ? $dtoClass : null;
    }

    /**
     * Create a DTO instance from array data.
     *
     * First checks for a custom createDTO method, then attempts to create
     * the DTO using the constructor with spread operator.
     */
    protected function createDTO(array $data, ?string $dtoClass = null): object
    {
        if ($this->hasMethod('createDTO')) {
            return $this->callMethod('createDTO', [$data]);
        }

        $dtoClass = $dtoClass ?? $this->getDTOClass();

        if (! $dtoClass || ! class_exists($dtoClass)) {
            return (object) $data;
        }

        // Try to instantiate with named arguments (PHP 8+)
        try {
            $reflection = new \ReflectionClass($dtoClass);
            $constructor = $reflection->getConstructor();

            if ($constructor) {
                $parameters = $constructor->getParameters();
                $args = [];

                foreach ($parameters as $param) {
                    $name = $param->getName();
                    $args[$name] = $data[$name] ?? ($param->isDefaultValueAvailable() ? $param->getDefaultValue() : null);
                }

                return new $dtoClass(...$args);
            }

            return new $dtoClass(...$data);
        } catch (\Exception $e) {
            // Fallback to object cast if DTO creation fails
            return (object) $data;
        }
    }
}
