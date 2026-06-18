<?php

declare(strict_types=1);

namespace App\Actions\Concerns;

use App\Actions\Concerns\AsReversible;
use App\Actions\Helpers\ArgumentExtractor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ProvidesEditResourceHelpers
{


    /**
     * Get resource name from class name.
     *
     * Extracts resource name from class name for broadcast naming.
     *
     * @return string Resource name
     */
    protected function getResourceName(): string
    {
        $className = class_basename(static::class);
        // Remove "Update" or "Edit" prefix if present
        $resource = preg_replace('/^(Update|Edit)/', '', $className);

        return strtolower($resource);
    }

    /**
     * Extract and type arguments from variadic array.
     *
     * Helper method that delegates to ArgumentExtractor for consistency.
     * This method is kept for backward compatibility and convenience.
     *
     * @param  array<int, mixed>  $arguments  The variadic arguments array
     * @param  string|null  ...$types  Optional type hints for each argument
     * @return array<int, mixed> Extracted arguments
     *
     * @example
     * // Extract two arguments: Tag and array
     * [$tag, $formData] = $this->extractArguments($arguments, Tag::class, 'array');
     */
    protected function extractArguments(array $arguments, ?string ...$types): array
    {
        return ArgumentExtractor::extract($arguments, ...$types);
    }

    /**
     * Dispatch this action as an event after successful update.
     *
     * Automatically dispatches the action as a Laravel event, allowing
     * other parts of the application to react to the update.
     *
     * @param  mixed  ...$arguments  The action arguments
     *
     * @example
     * // In your handle() method after update:
     * $tag->update($formData);
     * $this->dispatchUpdatedEvent($tag, $team);
     */
    protected function dispatchUpdatedEvent(...$arguments): void
    {
        static::dispatch(...$arguments);
    }

    /**
     * Dispatch this action as an event conditionally.
     *
     * Only dispatches if the condition is true.
     *
     * @param  bool  $condition  Whether to dispatch
     * @param  mixed  ...$arguments  The action arguments
     */
    protected function dispatchUpdatedEventIf(bool $condition, ...$arguments): void
    {
        static::dispatchIf($condition, ...$arguments);
    }

    /**
     * Validate and sanitize input data.
     *
     * Helper method for common validation and sanitization patterns.
     *
     * @param  array<string, mixed>  $data  Input data
     * @param  array<string, mixed>  $rules  Validation rules
     * @return array<string, mixed> Validated and sanitized data
     */
    protected function validateAndSanitize(array $data, array $rules): array
    {
        $validated = validator($data, $rules)->validate();

        // Common sanitization
        foreach ($validated as $key => $value) {
            if (is_string($value)) {
                $validated[$key] = trim($value);
            }
        }

        return $validated;
    }

    /**
     * Check if action should be executed based on feature flags or conditions.
     *
     * Override this method to add conditional execution logic.
     *
     * @param  array<int, mixed>  $arguments  The action arguments
     * @return bool Whether action should execute
     */
    protected function shouldExecute(array $arguments): bool
    {
        return true; // Override to add custom logic
    }

    /**
     * Prepare data before update.
     *
     * Override this method to transform or prepare data before it's used in handle().
     *
     * @param  array<string, mixed>  $data  Raw input data
     * @param  mixed  $resource  The resource being updated
     * @return array<string, mixed> Prepared data
     */
    protected function prepareData(array $data, mixed $resource): array
    {
        // Add common preparation logic here
        // e.g., set timestamps, user IDs, etc.
        return $data;
    }

    /**
     * Perform post-update tasks.
     *
     * Override this method to perform tasks after successful update.
     *
     * @param  mixed  $result  The updated resource
     * @param  array<int, mixed>  $arguments  The action arguments
     */
    protected function afterUpdate(mixed $result, array $arguments): void
    {
        // Override to perform post-update tasks
        // e.g., send notifications, update related models, etc.
    }

    /**
     * Perform pre-update tasks.
     *
     * Override this method to perform tasks before update.
     *
     * @param  mixed  $resource  The resource being updated
     * @param  array<int, mixed>  $arguments  The action arguments
     */
    protected function beforeUpdate(mixed $resource, array $arguments): void
    {
        // Override to perform pre-update tasks
        // e.g., validate business rules, check quotas, etc.
    }

    /**
     * Get default attributes for updated resources.
     *
     * Returns common attributes that should be set on updated resources.
     *
     * @return array<string, mixed> Default attributes
     */
    protected function getDefaultAttributes(): array
    {
        return [
            'updated_by' => $this->currentUserId(),
            'updated_at' => now(),
        ];
    }

    /**
     * Merge default attributes with provided data.
     *
     * Combines default attributes with user-provided data.
     *
     * @param  array<string, mixed>  $data  User-provided data
     * @return array<string, mixed> Merged data with defaults
     */
    protected function mergeDefaults(array $data): array
    {
        return array_merge($this->getDefaultAttributes(), $data);
    }

    /**
     * Check if user has permission to update resource.
     *
     * Convenience method that uses the authorization system.
     *
     * @param  mixed  ...$arguments  The action arguments
     * @return bool Whether user has permission
     */
    protected function canUpdate(...$arguments): bool
    {
        $ability = $this->getAuthorizationAbility();
        $authArguments = $this->getAuthorizationArguments(...$arguments);

        return Gate::allows($ability, $authArguments);
    }

    /**
     * Ensure user has permission to update resource.
     *
     * Throws authorization exception if user doesn't have permission.
     *
     * @param  mixed  ...$arguments  The action arguments
     *
     * @throws AuthorizationException
     */
    protected function ensureCanUpdate(...$arguments): void
    {
        if (! $this->canUpdate(...$arguments)) {
            abort(403, 'This action is unauthorized.');
        }
    }

    /**
     * Get resource type name.
     *
     * Extracts resource type from class name (e.g., "UpdateTag" -> "tag").
     *
     * @return string Resource type name
     */
    protected function getResourceType(): string
    {
        return $this->getResourceName();
    }

    /**
     * Get full resource identifier.
     *
     * Returns a full identifier like "module.resource" (e.g., "tags.tag").
     *
     * @return string Full resource identifier
     */
    protected function getFullResourceIdentifier(): string
    {
        $module = $this->getModuleName();
        $resource = $this->getResourceType();

        return $module && $resource ? "{$module}.{$resource}" : $resource;
    }

    /**
     * Get the resource being updated from arguments.
     *
     * Helper method to extract the resource (typically first argument).
     *
     * @param  array<int, mixed>  $arguments  The action arguments
     * @param  string  $resourceClass  Expected resource class
     * @return mixed The resource being updated
     */
    protected function getResource(array $arguments, string $resourceClass): mixed
    {
        [$resource] = ArgumentExtractor::extract($arguments, $resourceClass);

        return $resource;
    }

    /**
     * Get the original state of the resource before update.
     *
     * Useful for tracking changes and audit trails.
     *
     * @param  mixed  $resource  The resource being updated
     * @return array<string, mixed> Original state (attributes)
     */
    protected function getOriginalState(mixed $resource): array
    {
        if (method_exists($resource, 'getOriginal')) {
            return $resource->getOriginal();
        }

        if (method_exists($resource, 'getAttributes')) {
            return $resource->getAttributes();
        }

        return [];
    }

    /**
     * Get the changed attributes.
     *
     * Returns an array of attributes that have changed.
     *
     * @param  mixed  $resource  The resource being updated
     * @return array<string, mixed> Changed attributes
     */
    protected function getChangedAttributes(mixed $resource): array
    {
        if (method_exists($resource, 'getChanges')) {
            return $resource->getChanges();
        }

        if (method_exists($resource, 'getDirty')) {
            return $resource->getDirty();
        }

        return [];
    }

    /**
     * Check if resource has changes.
     *
     * Returns true if the resource has any changed attributes.
     *
     * @param  mixed  $resource  The resource being updated
     * @return bool Whether resource has changes
     */
    protected function hasChanges($resource): bool
    {
        if (method_exists($resource, 'isDirty')) {
            return $resource->isDirty();
        }

        return ! empty($this->getChangedAttributes($resource));
    }

    /**
     * Get before/after comparison data.
     *
     * Returns a comparison of original and new values.
     *
     * @param  mixed  $resource  The resource being updated
     * @return array<string, array<string, mixed>> Before/after comparison
     */
    protected function getChangeComparison(mixed $resource): array
    {
        $original = $this->getOriginalState($resource);
        $changed = $this->getChangedAttributes($resource);
        $comparison = [];

        foreach ($changed as $key => $newValue) {
            $comparison[$key] = [
                'before' => $original[$key] ?? null,
                'after' => $newValue,
            ];
        }

        return $comparison;
    }

    /**
     * Store reversal data for undo capability.
     *
     * Used by AsReversible to store data needed to undo the update.
     *
     * @param  mixed  $resource  The resource being updated
     * @param  array<string, mixed>  $additionalData  Additional reversal data
     */
    protected function storeReversalData(mixed $resource, array $additionalData = []): void
    {
        if (method_exists($this, 'setReversalData')) {
            $this->setReversalData(array_merge([
                'resource_id' => $resource->id ?? null,
                'resource_type' => get_class($resource),
                'original_state' => $this->getOriginalState($resource),
            ], $additionalData));
        }
    }
}
