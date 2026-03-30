<?php

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Decorates actions when used as API resources.
 *
 * @example
 * // When an action with AsResource is used:
 * return new UserResource($user);
 *
 * // This decorator extends JsonResource and calls toArray()
 * // or handle() on the action to transform the resource data.
 * // Supports both single resources and collections.
 */
class ResourceDecorator extends JsonResource
{
    use DecorateActions;

    protected $actionInstance;

    public function __construct($action, $resource = null)
    {
        $this->actionInstance = is_string($action) ? app($action) : $action;
        $this->setAction($this->actionInstance);
        parent::__construct($resource ?? $this->actionInstance);
    }

    public function toArray($request): array
    {
        // Call toArray on the action if it exists
        if ($this->hasMethod('toArray')) {
            return $this->callMethod('toArray', [$request]);
        }

        // Call handle on the action if it exists
        if ($this->hasMethod('handle')) {
            $result = $this->callMethod('handle', [$this->resource, $request]);

            return is_array($result) ? $result : (array) $result;
        }

        // Fallback to parent implementation
        return parent::toArray($request);
    }

    public static function collection($resource)
    {
        // Use parent collection method if available
        if (method_exists(parent::class, 'collection')) {
            return parent::collection($resource);
        }

        // Otherwise, map each resource through the decorator
        $actionClass = static::class;
        $actionInstance = app($actionClass);

        return collect($resource)->map(function ($item) use ($actionClass, $actionInstance) {
            return new $actionClass($actionInstance, $item);
        });
    }
}
