<?php

namespace App\Actions\Concerns;

use App\Actions\Attributes\UpdateDispatchEvent;
use App\Actions\Attributes\UpdateEventClass;
use App\Actions\Attributes\UpdateTrackChanges;
use App\Actions\Decorators\UpdateDecorator;
use App\Actions\DesignPatterns\UpdateDesignPattern;

/**
 * Automatically tracks changes and dispatches events for update operations.
 *
 * Uses the decorator pattern to automatically wrap actions and provide update-specific
 * functionality. The UpdateDecorator intercepts handle() calls and tracks changes,
 * dispatches events, and provides metadata about what was updated.
 *
 * How it works:
 * 1. When an action uses AsUpdatable, UpdateDesignPattern recognizes it
 * 2. ActionManager wraps the action with UpdateDecorator
 * 3. When handle() is called, the decorator:
 *    - Executes the action's handle() method
 *    - Tracks which fields were changed (if result is an Eloquent model)
 *    - Adds metadata about the update (_updated_fields, _update_metadata)
 *    - Optionally dispatches update events
 *    - Returns the result
 *
 * @example
 * // ============================================
 * // Example 1: Minimal Setup (Default Behavior)
 * // ============================================
 * class UpdateUser extends Actions
 * {
 *     use AsUpdatable;
 *
 *     public function handle(User $user, array $data): User
 *     {
 *         $user->update($data);
 *         return $user->fresh();
 *     }
 * }
 *
 * // Usage - change tracking happens automatically:
 * $user = UpdateUser::run($user, ['name' => 'New Name', 'email' => 'new@example.com']);
 * // $user->_updated_fields = ['name', 'email']
 * // $user->_update_metadata = [
 * //     'changed_at' => '2024-01-15T10:30:00Z',
 * //     'changed_by' => 123, // Current user ID
 * // ]
 * @example
 * // ============================================
 * // Example 2: Full Configuration (Using Attributes)
 * // ============================================
 * use App\Actions\Attributes\UpdateDispatchEvent;
 * use App\Actions\Attributes\UpdateEventClass;
 * use App\Actions\Attributes\UpdateTrackChanges;
 *
 * #[UpdateTrackChanges(true)]
 * #[UpdateDispatchEvent(true)]
 * #[UpdateEventClass(UserUpdated::class)]
 * class UpdateUser extends Actions
 * {
 *     use AsUpdatable;
 *
 *     public function handle(User $user, array $data): User
 *     {
 *         $user->update($data);
 *         return $user->fresh();
 *     }
 * }
 *
 * // Usage:
 * $user = UpdateUser::run($user, ['name' => 'New Name']);
 * // Changes tracked in $user->_updated_fields
 * // UserUpdated event dispatched automatically
 * @example
 * // ============================================
 * // Example 3: Using Methods Instead of Attributes
 * // ============================================
 * class UpdateUser extends Actions
 * {
 *     use AsUpdatable;
 *
 *     public function handle(User $user, array $data): User
 *     {
 *         $user->update($data);
 *         return $user->fresh();
 *     }
 *
 *     // Control change tracking
 *     public function shouldTrackChanges(): bool
 *     {
 *         // Disable in testing environment
 *         return ! app()->environment('testing');
 *     }
 *
 *     // Enable event dispatching
 *     public function shouldDispatchEvent(): bool
 *     {
 *         return true; // Default: false
 *     }
 *
 *     // Specify event class to dispatch
 *     public function getUpdateEventClass(): string
 *     {
 *         return UserUpdated::class;
 *     }
 * }
 * @example
 * // ============================================
 * // Example 4: Disabling Change Tracking
 * // ============================================
 * // Option 1: Using attribute
 * #[UpdateTrackChanges(false)]
 * class UpdateUser extends Actions
 * {
 *     use AsUpdatable;
 *
 *     public function handle(User $user, array $data): User
 *     {
 *         $user->update($data);
 *         return $user->fresh();
 *     }
 * }
 *
 * // Option 2: Using method
 * class UpdateUser extends Actions
 * {
 *     use AsUpdatable;
 *
 *     public function handle(User $user, array $data): User
 *     {
 *         $user->update($data);
 *         return $user->fresh();
 *     }
 *
 *     public function shouldTrackChanges(): bool
 *     {
 *         return false; // Disable change tracking
 *     }
 * }
 * @example
 * // ============================================
 * // Example 5: Event Dispatching
 * // ============================================
 * use App\Actions\Attributes\UpdateDispatchEvent;
 * use App\Actions\Attributes\UpdateEventClass;
 *
 * #[UpdateDispatchEvent(true)]
 * #[UpdateEventClass(UserUpdated::class)]
 * class UpdateUser extends Actions
 * {
 *     use AsUpdatable;
 *
 *     public function handle(User $user, array $data): User
 *     {
 *         $user->update($data);
 *         return $user->fresh();
 *     }
 * }
 *
 * // Create the event class:
 * class UserUpdated
 * {
 *     public function __construct(
 *         public User $user,
 *         public array $arguments
 *     ) {}
 * }
 *
 * // Listen for the event:
 * Event::listen(UserUpdated::class, function (UserUpdated $event) {
 *     // Handle user update
 *     Log::info('User updated', ['user_id' => $event->user->id]);
 * });
 *
 * // Usage:
 * $user = UpdateUser::run($user, ['name' => 'New Name']);
 * // UserUpdated event is automatically dispatched
 * @example
 * // ============================================
 * // Example 6: Accessing Updated Fields
 * // ============================================
 * class UpdateUser extends Actions
 * {
 *     use AsUpdatable;
 *
 *     public function handle(User $user, array $data): User
 *     {
 *         $user->update($data);
 *         return $user->fresh();
 *     }
 * }
 *
 * // Usage:
 * $user = UpdateUser::run($user, [
 *     'name' => 'New Name',
 *     'email' => 'new@example.com',
 *     'phone' => '123-456-7890',
 * ]);
 *
 * // Access updated fields:
 * $updatedFields = $user->_updated_fields;
 * // ['name', 'email', 'phone']
 *
 * // Access update metadata:
 * $metadata = $user->_update_metadata;
 * // [
 * //     'changed_at' => '2024-01-15T10:30:00Z',
 * //     'changed_by' => 123,
 * // ]
 *
 * // Check if specific field was updated:
 * if (in_array('email', $user->_updated_fields)) {
 *     // Email was updated
 * }
 * @example
 * // ============================================
 * // Example 7: Real-World Usage Pattern
 * // ============================================
 * use App\Actions\Attributes\UpdateDispatchEvent;
 * use App\Actions\Attributes\UpdateEventClass;
 * use App\Actions\Attributes\UpdateTrackChanges;
 *
 * #[UpdateTrackChanges(true)]
 * #[UpdateDispatchEvent(true)]
 * #[UpdateEventClass(ProductUpdated::class)]
 * class UpdateProduct extends Actions
 * {
 *     use AsUpdatable;
 *
 *     public function handle(Product $product, array $data): Product
 *     {
 *         // Only update allowed fields
 *         $allowedFields = ['name', 'description', 'price', 'status'];
 *         $updateData = array_intersect_key($data, array_flip($allowedFields));
 *
 *         $product->update($updateData);
 *         return $product->fresh();
 *     }
 * }
 *
 * // Usage:
 * $product = UpdateProduct::run($product, [
 *     'name' => 'Updated Product Name',
 *     'price' => 99.99,
 * ]);
 *
 * // Check what changed:
 * if (in_array('price', $product->_updated_fields)) {
 *     // Price was updated - maybe send notification
 *     Notification::send($product->owner, new PriceChangedNotification($product));
 * }
 * @example
 * // ============================================
 * // Example 8: Conditional Change Tracking
 * // ============================================
 * class UpdateUser extends Actions
 * {
 *     use AsUpdatable;
 *
 *     public function handle(User $user, array $data): User
 *     {
 *         $user->update($data);
 *         return $user->fresh();
 *     }
 *
 *     // Conditionally enable change tracking
 *     public function shouldTrackChanges(): bool
 *     {
 *         // Only track changes for important fields
 *         $importantFields = ['email', 'role', 'status'];
 *         $hasImportantChanges = ! empty(array_intersect_key(
 *             request()->input(),
 *             array_flip($importantFields)
 *         ));
 *
 *         return $hasImportantChanges;
 *     }
 *
 *     // Conditionally dispatch events
 *     public function shouldDispatchEvent(): bool
 *     {
 *         // Only dispatch if user is active
 *         return $this->user->isActive();
 *     }
 * }
 * @example
 * // ============================================
 * // Default Behavior
 * // ============================================
 * // Default change tracking: enabled (true)
 * // Default event dispatching: disabled (false)
 * // Default event class: null (no event dispatched)
 * //
 * // Change tracking only works with Eloquent models that have getChanges() method.
 * // The decorator checks if the result is a model and has changes before tracking.
 * //
 * // Updated fields are stored in _updated_fields property (array of field names).
 * // Update metadata is stored in _update_metadata property:
 * // - 'changed_at': ISO 8601 timestamp
 * // - 'changed_by': Authenticated user ID (if available)
 * //
 * // Priority order for configuration:
 * // 1. PHP attributes (#[UpdateTrackChanges], etc.)
 * // 2. Methods (shouldTrackChanges(), shouldDispatchEvent(), getUpdateEventClass())
 * // 3. Default values
 *
 * @see UpdateDecorator
 * @see UpdateDesignPattern
 * @see UpdateTrackChanges
 * @see UpdateDispatchEvent
 * @see UpdateEventClass
 */
trait AsUpdatable
{
    //
}
