<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsResource;
use App\Actions\Decorators\ResourceDecorator;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Recognizes when actions are used as API resources.
 *
 * @example
 * // Action class:
 * class UserResource extends Actions
 * {
 *     use AsResource;
 *
 *     public function toArray($request): array
 *     {
 *         return [
 *             'id' => $this->resource->id,
 *             'name' => $this->resource->name,
 *             'email' => $this->resource->email,
 *             'created_at' => $this->resource->created_at,
 *         ];
 *     }
 * }
 *
 * // Usage in controller:
 * return new UserResource($user);
 * // or
 * return UserResource::collection($users);
 *
 * // The design pattern automatically recognizes when the action
 * // is used as a resource and decorates it appropriately.
 */
class ResourceDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsResource::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        return $frame->instanceOf(JsonResource::class)
            || $frame->matches(JsonResource::class, 'toArray')
            || $frame->matches(JsonResource::class, 'resolve');
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        // Try to get the resource from the frame object
        $frameObject = $frame->getObject();
        $resource = null;

        if ($frameObject instanceof JsonResource) {
            $resource = $frameObject->resource;
        } elseif (is_object($frameObject) && property_exists($frameObject, 'resource')) {
            $resource = $frameObject->resource;
        }

        return app(ResourceDecorator::class, [
            'action' => $instance,
            'resource' => $resource,
        ]);
    }
}
