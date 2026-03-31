<?php

namespace App\Actions\Concerns;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Allows actions to be used as API resources.
 *
 * Provides API resource transformation capabilities for actions, allowing them
 * to be used as Laravel JsonResource classes. Works with ResourceDecorator
 * to automatically wrap actions when used as resources.
 *
 * How it works:
 * - ResourceDesignPattern recognizes actions using AsResource
 * - ActionManager wraps the action with ResourceDecorator (extends JsonResource)
 * - When used as a resource, the decorator calls `toArray()` or `handle()`
 * - Supports single resources and collections
 * - Allows metadata via `with()` method
 *
 * Benefits:
 * - Use actions as API resources
 * - Consistent resource transformation
 * - Automatic collection support
 * - Metadata support
 * - Conditional fields
 * - Relationship handling
 * - Works with Laravel's resource system
 *
 * Note: This trait works WITH a decorator (ResourceDecorator) that
 * automatically wraps actions when used as JsonResource instances. The trait
 * provides utility methods (`toArray()`, `with()`, `collection()`), while
 * the decorator handles the JsonResource interface implementation.
 * This hybrid approach gives you both convenience and automatic decoration.
 *
 * Resource Methods:
 * - `toArray($request)`: Transforms the resource to an array (calls `handle()` if exists)
 * - `with($request)`: Adds metadata to the response (calls `getResourceWith()` if exists)
 * - `collection($resource)`: Creates a collection of resources
 *
 * @example
 * // Basic usage - single resource:
 * class UserResource extends Actions
 * {
 *     use AsResource;
 *
 *     public function handle(Request $request): array
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
 * // Returns: {"id": 1, "name": "John", "email": "john@example.com", ...}
 * @example
 * // Using with collections:
 * class PostResource extends Actions
 * {
 *     use AsResource;
 *
 *     public function handle(Request $request): array
 *     {
 *         return [
 *             'id' => $this->resource->id,
 *             'title' => $this->resource->title,
 *             'content' => $this->resource->content,
 *             'author' => $this->resource->user->name,
 *         ];
 *     }
 * }
 *
 * // Usage in controller:
 * return PostResource::collection($posts);
 * // Returns: {"data": [{"id": 1, "title": "...", ...}, ...]}
 * @example
 * // Adding metadata with with() method:
 * class ProductResource extends Actions
 * {
 *     use AsResource;
 *
 *     public function handle(Request $request): array
 *     {
 *         return [
 *             'id' => $this->resource->id,
 *             'name' => $this->resource->name,
 *             'price' => $this->resource->price,
 *         ];
 *     }
 *
 *     public function getResourceWith(Request $request): array
 *     {
 *         return [
 *             'meta' => [
 *                 'version' => '1.0',
 *                 'timestamp' => now()->toIso8601String(),
 *             ],
 *             'links' => [
 *                 'self' => route('products.show', $this->resource),
 *             ],
 *         ];
 *     }
 * }
 *
 * // Usage:
 * return new ProductResource($product);
 * // Returns: {"data": {...}, "meta": {...}, "links": {...}}
 * @example
 * // Conditional fields based on request:
 * class OrderResource extends Actions
 * {
 *     use AsResource;
 *
 *     public function handle(Request $request): array
 *     {
 *         $data = [
 *             'id' => $this->resource->id,
 *             'total' => $this->resource->total,
 *             'status' => $this->resource->status,
 *         ];
 *
 *         // Include sensitive data only for authenticated user who owns the order
 *         if ($request->user() && $request->user()->id === $this->resource->user_id) {
 *             $data['payment_method'] = $this->resource->payment_method;
 *             $data['billing_address'] = $this->resource->billing_address;
 *         }
 *
 *         return $data;
 *     }
 * }
 *
 * // Usage:
 * return new OrderResource($order);
 * // Returns different data based on authenticated user
 * @example
 * // Including relationships:
 * class TeamResource extends Actions
 * {
 *     use AsResource;
 *
 *     public function handle(Request $request): array
 *     {
 *         return [
 *             'id' => $this->resource->id,
 *             'name' => $this->resource->name,
 *             'members' => $this->resource->members->map(function ($member) {
 *                 return [
 *                     'id' => $member->id,
 *                     'name' => $member->name,
 *                     'role' => $member->pivot->role,
 *                 ];
 *             }),
 *             'created_at' => $this->resource->created_at,
 *         ];
 *     }
 * }
 *
 * // Usage:
 * return new TeamResource($team);
 * // Includes nested member data
 * @example
 * // Nested resources:
 * class CommentResource extends Actions
 * {
 *     use AsResource;
 *
 *     public function handle(Request $request): array
 *     {
 *         return [
 *             'id' => $this->resource->id,
 *             'body' => $this->resource->body,
 *             'author' => new UserResource($this->resource->user),
 *             'replies' => CommentResource::collection($this->resource->replies),
 *         ];
 *     }
 * }
 *
 * // Usage:
 * return new CommentResource($comment);
 * // Returns nested user resource and collection of reply resources
 * @example
 * // Paginated collections:
 * class ArticleResource extends Actions
 * {
 *     use AsResource;
 *
 *     public function handle(Request $request): array
 *     {
 *         return [
 *             'id' => $this->resource->id,
 *             'title' => $this->resource->title,
 *             'excerpt' => $this->resource->excerpt,
 *             'published_at' => $this->resource->published_at,
 *         ];
 *     }
 *
 *     public function getResourceWith(Request $request): array
 *     {
 *         return [
 *             'meta' => [
 *                 'current_page' => $request->input('page', 1),
 *                 'per_page' => 15,
 *             ],
 *         ];
 *     }
 * }
 *
 * // Usage in controller:
 * $articles = Article::paginate(15);
 * return ArticleResource::collection($articles);
 * // Returns paginated collection with metadata
 * @example
 * // API versioning with resources:
 * class ApiV1UserResource extends Actions
 * {
 *     use AsResource;
 *
 *     public function handle(Request $request): array
 *     {
 *         return [
 *             'id' => $this->resource->id,
 *             'name' => $this->resource->name,
 *             'email' => $this->resource->email,
 *         ];
 *     }
 * }
 *
 * class ApiV2UserResource extends Actions
 * {
 *     use AsResource;
 *
 *     public function handle(Request $request): array
 *     {
 *         return [
 *             'id' => $this->resource->id,
 *             'full_name' => $this->resource->name,
 *             'email_address' => $this->resource->email,
 *             'profile' => [
 *                 'avatar' => $this->resource->avatar_url,
 *                 'bio' => $this->resource->bio,
 *             ],
 *         ];
 *     }
 * }
 *
 * // Usage in controller:
 * $version = $request->header('API-Version', 'v1');
 * $resourceClass = $version === 'v2' ? ApiV2UserResource::class : ApiV1UserResource::class;
 * return new $resourceClass($user);
 * @example
 * // Combining with other concerns:
 * class TransactionResource extends Actions
 * {
 *     use AsResource;
 *     use AsSerializable;
 *
 *     public function handle(Request $request): array
 *     {
 *         return [
 *             'id' => $this->resource->id,
 *             'amount' => $this->resource->amount,
 *             'status' => $this->resource->status,
 *             'processed_at' => $this->resource->processed_at,
 *         ];
 *     }
 *
 *     public function getResourceWith(Request $request): array
 *     {
 *         return [
 *             'meta' => [
 *                 'serialized_at' => now()->toIso8601String(),
 *                 'action_class' => get_class($this),
 *             ],
 *         ];
 *     }
 * }
 *
 * // Usage:
 * return new TransactionResource($transaction);
 * // Combines resource transformation with serialization
 * @example
 * // Custom toArray() override:
 * class CustomResource extends Actions
 * {
 *     use AsResource;
 *
 *     public function toArray(Request $request): array
 *     {
 *         // Override toArray directly instead of using handle()
 *         return [
 *             'custom_id' => $this->resource->id,
 *             'custom_name' => $this->resource->name,
 *             'transformed_at' => now()->toIso8601String(),
 *         ];
 *     }
 * }
 *
 * // Usage:
 * return new CustomResource($model);
 * // Uses custom toArray() method directly
 * @example
 * // Resource with computed properties:
 * class InvoiceResource extends Actions
 * {
 *     use AsResource;
 *
 *     public function handle(Request $request): array
 *     {
 *         return [
 *             'id' => $this->resource->id,
 *             'number' => $this->resource->number,
 *             'subtotal' => $this->resource->subtotal,
 *             'tax' => $this->resource->tax,
 *             'total' => $this->resource->subtotal + $this->resource->tax,
 *             'is_overdue' => $this->resource->due_date < now(),
 *             'days_until_due' => max(0, now()->diffInDays($this->resource->due_date, false)),
 *         ];
 *     }
 * }
 *
 * // Usage:
 * return new InvoiceResource($invoice);
 * // Includes computed properties like total, is_overdue, etc.
 * @example
 * // Resource with eager loading optimization:
 * class ProjectResource extends Actions
 * {
 *     use AsResource;
 *
 *     public function handle(Request $request): array
 *     {
 *         // Assume relationships are eager loaded
 *         return [
 *             'id' => $this->resource->id,
 *             'name' => $this->resource->name,
 *             'owner' => [
 *                 'id' => $this->resource->owner->id,
 *                 'name' => $this->resource->owner->name,
 *             ],
 *             'tasks' => $this->resource->tasks->map(function ($task) {
 *                 return [
 *                     'id' => $task->id,
 *                     'title' => $task->title,
 *                     'status' => $task->status,
 *                 ];
 *             }),
 *         ];
 *     }
 * }
 *
 * // Usage in controller (with eager loading):
 * $projects = Project::with(['owner', 'tasks'])->get();
 * return ProjectResource::collection($projects);
 * // Avoids N+1 queries
 * @example
 * // Resource with API response formatting:
 * class ApiResponseResource extends Actions
 * {
 *     use AsResource;
 *     use AsResponse;
 *
 *     public function handle(Request $request): array
 *     {
 *         return [
 *             'id' => $this->resource->id,
 *             'data' => $this->resource->toArray(),
 *         ];
 *     }
 *
 *     public function getResourceWith(Request $request): array
 *     {
 *         return [
 *             'meta' => [
 *                 'api_version' => '1.0',
 *                 'response_time' => microtime(true) - LARAVEL_START,
 *             ],
 *         ];
 *     }
 * }
 *
 * // Usage:
 * return new ApiResponseResource($model);
 * // Combines resource transformation with response building
 */
trait AsResource
{
    public function toArray($requestOrNotifiable): array
    {
        // If called with Request (for JsonResource), use resource logic
        if ($requestOrNotifiable instanceof Request) {
            return $this->hasMethod('handle')
                ? $this->callMethod('handle', [$requestOrNotifiable])
                : [];
        }

        // If called with notifiable (for Notification), use notification logic
        // This allows actions to work as both resources and notifications
        // Check for toNotificationArray method (from AsNotification trait pattern)
        if ($this->hasMethod('toNotificationArray')) {
            return $this->callMethod('toNotificationArray', [$requestOrNotifiable]);
        }

        // Fallback: try to call handle if it exists
        return $this->hasMethod('handle')
            ? $this->callMethod('handle', [$requestOrNotifiable])
            : [];
    }

    /**
     * Get the resource array representation (for JsonResource).
     * This is the primary method for resource transformation.
     */
    public function toResourceArray(Request $request): array
    {
        return $this->hasMethod('handle')
            ? $this->callMethod('handle', [$request])
            : [];
    }

    public function with(Request $request): array
    {
        return $this->hasMethod('getResourceWith')
            ? $this->callMethod('getResourceWith', [$request])
            : [];
    }

    public static function collection($resource)
    {
        // This should be implemented by the class using this trait
        // when extending JsonResource or ResourceCollection
        if (method_exists(get_called_class(), 'parent::collection')) {
            return parent::collection($resource);
        }

        return collect($resource)->map(fn ($item) => new static($item));
    }
}
