<?php

namespace App\Actions\Concerns;

use App\Actions\Attributes\Transformations;
use App\Actions\Attributes\TransformMode;
use App\Actions\Decorators\TransformerDecorator;
use App\Actions\DesignPatterns\TransformerDesignPattern;

/**
 * Automatically transforms action results using transformation rules.
 *
 * Uses the decorator pattern to automatically wrap actions and transform their results.
 * The TransformerDecorator intercepts handle() calls and applies transformations
 * to arrays and objects that can be converted to arrays.
 *
 * How it works:
 * 1. When an action uses AsTransformer, TransformerDesignPattern recognizes it
 * 2. ActionManager wraps the action with TransformerDecorator
 * 3. When handle() is called, the decorator:
 *    - Executes the action's handle() method
 *    - Applies transformation rules to the result
 *    - Returns the transformed result
 *
 * @example
 * // ============================================
 * // Example 1: Simple Key Renaming (Using Attributes)
 * // ============================================
 * use App\Actions\Attributes\Transformations;
 * use App\Actions\Attributes\TransformMode;
 *
 * #[Transformations([
 *     'id' => 'user_id',
 *     'email' => 'email_address',
 *     'name' => 'full_name',
 * ])]
 * #[TransformMode('nested')]
 * class GetUser extends Actions
 * {
 *     use AsTransformer;
 *
 *     public function handle(User $user): User
 *     {
 *         return $user;
 *     }
 * }
 *
 * // Usage - nested mode (default):
 * $user = GetUser::run($user);
 * // Access transformed data: $user->_transformed['user_id'], $user->_transformed['email_address']
 * @example
 * // ============================================
 * // Example 2: Value Transformation with Callables
 * // ============================================
 * use App\Actions\Attributes\Transformations;
 *
 * #[Transformations([
 *     'name' => fn($value) => strtoupper($value),
 *     'email' => fn($value) => strtolower($value),
 *     'created_at' => fn($value) => $value?->format('Y-m-d H:i:s'),
 *     'price' => fn($value) => number_format($value, 2),
 * ])]
 * class FormatProduct extends Actions
 * {
 *     use AsTransformer;
 *
 *     public function handle(Product $product): Product
 *     {
 *         return $product;
 *     }
 * }
 *
 * // Usage:
 * $product = FormatProduct::run($product);
 * // $product->_transformed['name'] = 'PRODUCT NAME' (uppercase)
 * // $product->_transformed['email'] = 'product@example.com' (lowercase)
 * // $product->_transformed['created_at'] = '2024-01-15 10:30:00' (formatted)
 * @example
 * // ============================================
 * // Example 3: Direct Mode (Apply to Object Properties)
 * // ============================================
 * use App\Actions\Attributes\Transformations;
 * use App\Actions\Attributes\TransformMode;
 *
 * #[Transformations([
 *     'id' => 'tag_id',
 *     'name' => 'tag_name',
 *     'slug' => 'tag_slug',
 * ])]
 * #[TransformMode('direct')] // Apply directly to object properties
 * class GetTag extends Actions
 * {
 *     use AsTransformer;
 *
 *     public function handle(Tag $tag): Tag
 *     {
 *         return $tag;
 *     }
 * }
 *
 * // Usage - direct mode:
 * $tag = GetTag::run($tag);
 * // Access transformed properties directly: $tag->tag_id, $tag->tag_name, $tag->tag_slug
 * // Original properties still available: $tag->id, $tag->name, $tag->slug
 * @example
 * // ============================================
 * // Example 4: Using Methods Instead of Attributes
 * // ============================================
 * class GetUser extends Actions
 * {
 *     use AsTransformer;
 *
 *     public function handle(User $user): User
 *     {
 *         return $user;
 *     }
 *
 *     // Define transformation rules
 *     public function getTransformations(): array
 *     {
 *         return [
 *             'id' => 'user_id',
 *             'email' => 'email_address',
 *             'name' => fn($value) => ucwords($value),
 *         ];
 *     }
 *
 *     // Control transformation mode
 *     public function shouldNestTransformed(): bool
 *     {
 *         return true; // true = nested (default), false = direct
 *     }
 * }
 * @example
 * // ============================================
 * // Example 5: Array Results
 * // ============================================
 * #[Transformations([
 *     'id' => 'order_id',
 *     'total' => fn($value) => number_format($value, 2),
 *     'status' => fn($value) => strtoupper($value),
 * ])]
 * class GetOrders extends Actions
 * {
 *     use AsTransformer;
 *
 *     public function handle(): array
 *     {
 *         return [
 *             ['id' => 1, 'total' => 99.99, 'status' => 'pending'],
 *             ['id' => 2, 'total' => 149.50, 'status' => 'completed'],
 *         ];
 *     }
 * }
 *
 * // Usage - arrays are transformed directly:
 * $orders = GetOrders::run();
 * // $orders = [
 * //     ['order_id' => 1, 'total' => '99.99', 'status' => 'PENDING'],
 * //     ['order_id' => 2, 'total' => '149.50', 'status' => 'COMPLETED'],
 * // ]
 * @example
 * // ============================================
 * // Example 6: Nested Transformations
 * // ============================================
 * #[Transformations([
 *     'id' => 'user_id',
 *     'profile' => [
 *         'first_name' => 'first_name',
 *         'last_name' => 'last_name',
 *         'bio' => fn($value) => substr($value, 0, 100), // Truncate bio
 *     ],
 *     'settings' => [
 *         'theme' => 'ui_theme',
 *         'notifications' => fn($value) => (bool) $value,
 *     ],
 * ])]
 * class GetUserWithProfile extends Actions
 * {
 *     use AsTransformer;
 *
 *     public function handle(User $user): User
 *     {
 *         return $user->load('profile', 'settings');
 *     }
 * }
 *
 * // Usage:
 * $user = GetUserWithProfile::run($user);
 * // Nested transformations applied to profile and settings relationships
 * @example
 * // ============================================
 * // Example 7: Real-World Usage (from Tags\Actions\Create)
 * // ============================================
 * use App\Actions\Attributes\Transformations;
 * use App\Actions\Attributes\TransformMode;
 *
 * #[Transformations([
 *     'id' => 'tag_id',
 *     'name' => 'tag_name',
 *     'slug' => 'tag_slug',
 * ])]
 * #[TransformMode('nested')]
 * class CreateTag extends Actions
 * {
 *     use AsTransformer;
 *
 *     public function handle(Team $team, array $data): Tag
 *     {
 *         $tag = Tag::create($data);
 *         $team->tags()->attach($tag);
 *         return $tag;
 *     }
 * }
 *
 * // Usage:
 * $tag = CreateTag::run($team, ['name' => 'New Tag']);
 * // Access transformed data: $tag->_transformed['tag_id'], $tag->_transformed['tag_name']
 * // Original properties still available: $tag->id, $tag->name, $tag->slug
 * @example
 * // ============================================
 * // Example 8: Dynamic Transformations
 * // ============================================
 * class GetUser extends Actions
 * {
 *     use AsTransformer;
 *
 *     public function handle(User $user): User
 *     {
 *         return $user;
 *     }
 *
 *     // Dynamic transformations based on context
 *     public function getTransformations(): array
 *     {
 *         $transformations = [
 *             'id' => 'user_id',
 *             'email' => 'email_address',
 *         ];
 *
 *         // Add version-specific transformations
 *         if ($this->getVersion() === 'v2') {
 *             $transformations['name'] = fn($value) => strtoupper($value);
 *         }
 *
 *         // Add role-specific transformations
 *         if (auth()->user()?->isAdmin()) {
 *             $transformations['internal_notes'] = 'admin_notes';
 *         }
 *
 *         return $transformations;
 *     }
 * }
 * @example
 * // ============================================
 * // Example 9: Disabling Transformation
 * // ============================================
 * class GetUser extends Actions
 * {
 *     use AsTransformer;
 *
 *     public function handle(User $user): User
 *     {
 *         return $user;
 *     }
 *
 *     // Disable transformation conditionally
 *     public function shouldTransform(): bool
 *     {
 *         // Disable in testing environment
 *         return ! app()->environment('testing');
 *     }
 * }
 * @example
 * // ============================================
 * // Transformation Rule Types
 * // ============================================
 * // 1. String: Rename key
 * //    'id' => 'user_id'  // Renames 'id' to 'user_id'
 * //
 * // 2. Callable: Transform value
 * //    'name' => fn($value) => strtoupper($value)  // Transforms value
 * //    Callable receives: ($value, $key, $data)
 * //
 * // 3. Array: Nested transformation
 * //    'profile' => ['first_name' => 'first_name', ...]  // Recursively transform nested data
 * //
 * // Transformation modes:
 * // - 'nested' (default): Store transformed data in _transformed property
 * //   Example: $user->_transformed['user_id']
 * // - 'direct': Apply transformed properties directly to object
 * //   Example: $user->user_id (alongside original $user->id)
 * @example
 * // ============================================
 * // Default Behavior
 * // ============================================
 * // Default transformation mode: 'nested' (true)
 * // Default enabled: true
 * //
 * // Transformations work with:
 * // - Arrays: Returns transformed array directly
 * // - Objects with toArray() method (Eloquent models): Transforms and stores in _transformed or applies directly
 * //
 * // Priority order for configuration:
 * // 1. PHP attributes (#[Transformations], #[TransformMode])
 * // 2. Methods (getTransformations(), shouldNestTransformed())
 * // 3. Default values
 * //
 * // If no transformations are defined (empty array), no transformation is applied.
 *
 * @see TransformerDecorator
 * @see TransformerDesignPattern
 * @see Transformations
 * @see TransformMode
 */
trait AsTransformer
{
    //
}
