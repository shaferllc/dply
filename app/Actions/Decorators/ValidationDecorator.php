<?php

namespace App\Actions\Decorators;

use App\Actions\Attributes\ValidationAttributes;
use App\Actions\Attributes\ValidationMessages;
use App\Actions\Attributes\ValidationRules;
use App\Actions\Concerns\DecorateActions;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ValidationDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    public function handle(...$arguments)
    {
        // Validate arguments before executing action
        $this->validateArguments($arguments);

        // Execute the action with validated arguments
        return $this->action->handle(...$arguments);
    }

    public function __invoke(...$arguments)
    {
        return $this->handle(...$arguments);
    }

    protected function validateArguments(array $arguments): void
    {
        $rules = $this->getValidationRules();

        if (empty($rules)) {
            return; // No validation rules defined
        }

        // Map arguments to rule keys
        $data = $this->mapArgumentsToData($arguments, $rules);

        $validator = Validator::make(
            $data,
            $rules,
            $this->getValidationMessages(),
            $this->getValidationAttributes()
        );

        // Allow custom validator modification
        if ($this->hasMethod('withValidator')) {
            $this->callMethod('withValidator', [$validator]);
        }

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    protected function getValidationRules(): array
    {
        // Check for attribute first
        $rules = $this->getAttributeValue(ValidationRules::class);
        if ($rules !== null) {
            return $rules;
        }

        // Fall back to method
        return $this->fromActionMethod('rules', [], []);
    }

    protected function getAttributeValue(string $attributeClass): ?array
    {
        // Unwrap decorators to get the original action
        $originalAction = $this->getOriginalAction();

        try {
            $reflection = new \ReflectionClass($originalAction);
            $attributes = $reflection->getAttributes($attributeClass);

            if (! empty($attributes)) {
                $attribute = $attributes[0]->newInstance();
                if ($attribute instanceof ValidationRules) {
                    return $attribute->rules;
                }
                if ($attribute instanceof ValidationMessages) {
                    return $attribute->messages;
                }
                if ($attribute instanceof ValidationAttributes) {
                    return $attribute->attributes;
                }
            }
        } catch (\ReflectionException $e) {
            // Attribute not found or can't be read
        }

        return null;
    }

    protected function getOriginalAction()
    {
        $action = $this->action;

        // Unwrap decorators to get the original action
        while (str_starts_with(get_class($action), 'App\\Actions\\Decorators\\')) {
            $reflection = new \ReflectionClass($action);
            if ($reflection->hasProperty('action')) {
                $property = $reflection->getProperty('action');
                $property->setAccessible(true);
                $action = $property->getValue($action);
            } else {
                break;
            }
        }

        return $action;
    }

    protected function getValidationMessages(): array
    {
        // Check for attribute first
        $messages = $this->getAttributeValue(ValidationMessages::class);
        if ($messages !== null) {
            return $messages;
        }

        // Fall back to method
        return $this->fromActionMethod('messages', [], []);
    }

    protected function getValidationAttributes(): array
    {
        // Check for attribute first
        $attributes = $this->getAttributeValue(ValidationAttributes::class);
        if ($attributes !== null) {
            return $attributes;
        }

        // Fall back to method
        return $this->fromActionMethod('attributes', [], []);
    }

    protected function mapArgumentsToData(array $arguments, array $rules): array
    {
        $data = [];

        // Find array arguments (these are typically the form data to validate)
        foreach ($arguments as $argument) {
            if (is_array($argument)) {
                $data = array_merge($data, $argument);
            }
        }

        // If no array found, try mapping by position (legacy support)
        if (empty($data)) {
            $ruleKeys = array_keys($rules);
            foreach ($ruleKeys as $index => $key) {
                if (isset($arguments[$index]) && ! is_object($arguments[$index])) {
                    $data[$key] = $arguments[$index];
                }
            }
        }

        return $data;
    }
}
