<?php

declare(strict_types=1);

namespace App\Actions\Concerns;

/**
 * Formats action responses as standardized API responses.
 *
 * This trait is a marker that enables automatic API response formatting via ApiResponseDecorator.
 * When an action uses AsApiResponse, ApiResponseDesignPattern recognizes it and
 * ActionManager wraps the action with ApiResponseDecorator.
 *
 * How it works:
 * 1. Action uses AsApiResponse trait (marker)
 * 2. ApiResponseDesignPattern recognizes the trait
 * 3. ActionManager wraps action with ApiResponseDecorator
 * 4. When handle() is called, the decorator:
 *    - Executes the action's handle() method
 *    - Wraps result in standardized JSON response format
 *    - Handles exceptions and formats error responses
 *    - Returns JsonResponse with appropriate status codes
 *
 * Features:
 * - Automatic JSON response formatting
 * - Standardized success/error structure
 * - Exception handling and error formatting
 * - Configurable response structure
 * - Configurable status codes
 * - Debug information in development
 * - Works with ActionManager's decorator system
 * - Can be composed with other decorators
 * - No trait conflicts (marker trait only)
 *
 * Benefits:
 * - Consistent API response format
 * - Automatic error handling
 * - Standardized structure across all endpoints
 * - Configurable per action
 * - No trait method conflicts
 * - Composable with other decorators
 *
 * Use Cases:
 * - REST API endpoints
 * - JSON API responses
 * - Standardized error handling
 * - Consistent response structure
 * - API versioning compatibility
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * ApiResponseDecorator, which automatically wraps actions and adds response formatting.
 * This follows the same pattern as AsDebounced, AsBroadcast, AsLock, and other
 * decorator-based concerns.
 *
 * Configuration:
 * - Set `formatResponse($data)` method to customize success response structure
 * - Set `formatError(\Throwable $exception)` method to customize error response structure
 * - Set `getSuccessStatusCode()` method to customize success status code (default: 200)
 * - Set `getErrorStatusCode(\Throwable $exception)` method to customize error status code
 *
 * @example
 * // ============================================
 * // Example 1: Basic API Response
 * // ============================================
 * class GetUsers extends Actions
 * {
 *     use AsApiResponse;
 *
 *     public function handle(): array
 *     {
 *         return User::all()->toArray();
 *     }
 * }
 *
 * // Usage
 * GetUsers::run();
 * // Returns: {"success": true, "data": [...]}
 * @example
 * // ============================================
 * // Example 2: Custom Response Format
 * // ============================================
 * class GetProducts extends Actions
 * {
 *     use AsApiResponse;
 *
 *     public function handle(): array
 *     {
 *         return Product::all()->toArray();
 *     }
 *
 *     protected function formatResponse($data): array
 *     {
 *         return [
 *             'success' => true,
 *             'data' => $data,
 *             'meta' => [
 *                 'timestamp' => now()->toIso8601String(),
 *                 'count' => count($data),
 *             ],
 *         ];
 *     }
 * }
 *
 * // Usage
 * GetProducts::run();
 * // Returns: {"success": true, "data": [...], "meta": {...}}
 * @example
 * // ============================================
 * // Example 3: Custom Success Status Code
 * // ============================================
 * class CreateUser extends Actions
 * {
 *     use AsApiResponse;
 *
 *     public function handle(array $data): User
 *     {
 *         return User::create($data);
 *     }
 *
 *     protected function getSuccessStatusCode(): int
 *     {
 *         return 201; // Created
 *     }
 * }
 *
 * // Usage
 * CreateUser::run(['name' => 'John', 'email' => 'john@example.com']);
 * // Returns 201 status code with success response
 * @example
 * // ============================================
 * // Example 4: Custom Error Formatting
 * // ============================================
 * class UpdateUser extends Actions
 * {
 *     use AsApiResponse;
 *
 *     public function handle(User $user, array $data): User
 *     {
 *         $user->update($data);
 *
 *         return $user->fresh();
 *     }
 *
 *     protected function formatError(\Throwable $exception): array
 *     {
 *         return [
 *             'success' => false,
 *             'error' => [
 *                 'message' => $exception->getMessage(),
 *                 'code' => $exception->getCode() ?: 'UPDATE_ERROR',
 *                 'type' => class_basename($exception),
 *             ],
 *         ];
 *     }
 * }
 *
 * // Usage
 * UpdateUser::run($user, ['email' => 'invalid']);
 * // Returns formatted error response on validation failure
 * @example
 * // ============================================
 * // Example 5: Custom Error Status Codes
 * // ============================================
 * class DeleteUser extends Actions
 * {
 *     use AsApiResponse;
 *
 *     public function handle(User $user): void
 *     {
 *         $user->delete();
 *     }
 *
 *     protected function getErrorStatusCode(\Throwable $exception): int
 *     {
 *         return match (true) {
 *             $exception instanceof \Illuminate\Validation\ValidationException => 422,
 *             $exception instanceof \Illuminate\Database\Eloquent\ModelNotFoundException => 404,
 *             $exception instanceof \Illuminate\Auth\Access\AuthorizationException => 403,
 *             default => 500,
 *         };
 *     }
 * }
 *
 * // Usage
 * DeleteUser::run($user);
 * // Returns appropriate status code based on exception type
 * @example
 * // ============================================
 * // Example 6: Paginated Response
 * // ============================================
 * class ListProducts extends Actions
 * {
 *     use AsApiResponse;
 *
 *     public function handle(): \Illuminate\Pagination\LengthAwarePaginator
 *     {
 *         return Product::paginate(20);
 *     }
 *
 *     protected function formatResponse($data): array
 *     {
 *         return [
 *             'success' => true,
 *             'data' => $data->items(),
 *             'pagination' => [
 *                 'current_page' => $data->currentPage(),
 *                 'per_page' => $data->perPage(),
 *                 'total' => $data->total(),
 *                 'last_page' => $data->lastPage(),
 *             ],
 *         ];
 *     }
 * }
 *
 * // Usage
 * ListProducts::run();
 * // Returns paginated response with metadata
 * @example
 * // ============================================
 * // Example 7: Resource Collection Response
 * // ============================================
 * class GetOrders extends Actions
 * {
 *     use AsApiResponse;
 *
 *     public function handle(): \Illuminate\Database\Eloquent\Collection
 *     {
 *         return Order::with('items')->get();
 *     }
 *
 *     protected function formatResponse($data): array
 *     {
 *         return [
 *             'success' => true,
 *             'data' => $data->map(fn($order) => [
 *                 'id' => $order->id,
 *                 'total' => $order->total,
 *                 'items' => $order->items->map(fn($item) => [
 *                     'id' => $item->id,
 *                     'name' => $item->name,
 *                     'quantity' => $item->quantity,
 *                 ]),
 *             ]),
 *         ];
 *     }
 * }
 *
 * // Usage
 * GetOrders::run();
 * // Returns formatted collection with nested relationships
 * @example
 * // ============================================
 * // Example 8: Empty Response
 * // ============================================
 * class ClearCache extends Actions
 * {
 *     use AsApiResponse;
 *
 *     public function handle(): void
 *     {
 *         Cache::flush();
 *     }
 *
 *     protected function formatResponse($data): array
 *     {
 *         return [
 *             'success' => true,
 *             'message' => 'Cache cleared successfully',
 *         ];
 *     }
 *
 *     protected function getSuccessStatusCode(): int
 *     {
 *         return 204; // No Content
 *     }
 * }
 *
 * // Usage
 * ClearCache::run();
 * // Returns 204 with success message
 * @example
 * // ============================================
 * // Example 9: Validation Error Handling
 * // ============================================
 * class CreatePost extends Actions
 * {
 *     use AsApiResponse;
 *
 *     public function handle(array $data): Post
 *     {
 *         $validated = validator($data, [
 *             'title' => 'required|min:3',
 *             'content' => 'required',
 *         ])->validate();
 *
 *         return Post::create($validated);
 *     }
 *
 *     protected function formatError(\Throwable $exception): array
 *     {
 *         if ($exception instanceof \Illuminate\Validation\ValidationException) {
 *             return [
 *                 'success' => false,
 *                 'error' => [
 *                     'message' => 'Validation failed',
 *                     'errors' => $exception->errors(),
 *                 ],
 *             ];
 *         }
 *
 *         return parent::formatError($exception);
 *     }
 *
 *     protected function getErrorStatusCode(\Throwable $exception): int
 *     {
 *         if ($exception instanceof \Illuminate\Validation\ValidationException) {
 *             return 422;
 *         }
 *
 *         return parent::getErrorStatusCode($exception);
 *     }
 * }
 *
 * // Usage
 * CreatePost::run(['title' => '']); // Returns 422 with validation errors
 * @example
 * // ============================================
 * // Example 10: API Version-Specific Format
 * // ============================================
 * class GetUserData extends Actions
 * {
 *     use AsApiResponse;
 *     use AsApiVersion;
 *
 *     public function handle(User $user): array
 *     {
 *         $version = $this->getApiVersion();
 *
 *         return match ($version) {
 *             'v1' => ['id' => $user->id, 'name' => $user->name],
 *             'v2' => [
 *                 'id' => $user->id,
 *                 'name' => $user->name,
 *                 'email' => $user->email,
 *                 'avatar' => $user->avatar_url,
 *             ],
 *             default => ['id' => $user->id, 'name' => $user->name],
 *         };
 *     }
 *
 *     protected function formatResponse($data): array
 *     {
 *         $version = $this->getApiVersion();
 *
 *         return match ($version) {
 *             'v1' => ['status' => 'ok', 'user' => $data],
 *             'v2' => ['success' => true, 'data' => $data],
 *             default => ['success' => true, 'data' => $data],
 *         };
 *     }
 * }
 *
 * // Usage
 * GetUserData::run($user);
 * // Response format depends on API version
 * @example
 * // ============================================
 * // Example 11: Response with Metadata
 * // ============================================
 * class SearchProducts extends Actions
 * {
 *     use AsApiResponse;
 *
 *     public function handle(string $query): \Illuminate\Database\Eloquent\Collection
 *     {
 *         return Product::where('name', 'like', "%{$query}%")->get();
 *     }
 *
 *     protected function formatResponse($data): array
 *     {
 *         return [
 *             'success' => true,
 *             'data' => $data->toArray(),
 *             'meta' => [
 *                 'query' => request()->input('q'),
 *                 'count' => $data->count(),
 *                 'execution_time' => microtime(true) - LARAVEL_START,
 *             ],
 *         ];
 *     }
 * }
 *
 * // Usage
 * SearchProducts::run('laptop');
 * // Returns results with query metadata
 * @example
 * // ============================================
 * // Example 12: Conditional Response Format
 * // ============================================
 * class GetData extends Actions
 * {
 *     use AsApiResponse;
 *
 *     public function handle(): array
 *     {
 *         return ['key' => 'value'];
 *     }
 *
 *     protected function formatResponse($data): array
 *     {
 *         // Different format based on request type
 *         if (request()->wantsJson()) {
 *             return [
 *                 'success' => true,
 *                 'data' => $data,
 *             ];
 *         }
 *
 *         // For API requests, always use standard format
 *         return [
 *             'success' => true,
 *             'data' => $data,
 *             'format' => 'json',
 *         ];
 *     }
 * }
 *
 * // Usage
 * GetData::run();
 * // Returns format based on request type
 */
trait AsApiResponse
{
    // This is a marker trait - the actual API response formatting functionality is handled by ApiResponseDecorator
    // via the ApiResponseDesignPattern and ActionManager
}
