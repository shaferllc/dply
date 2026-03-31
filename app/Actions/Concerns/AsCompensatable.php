<?php

declare(strict_types=1);

namespace App\Actions\Concerns;

use App\Actions\Decorators\CompensationDecorator;

/**
 * Provides compensation/rollback support for actions (Saga pattern).
 *
 * This trait is a marker that enables automatic compensation tracking via CompensationDecorator.
 * When an action uses AsCompensatable, CompensationDesignPattern recognizes it and
 * ActionManager wraps the action with CompensationDecorator.
 *
 * How it works:
 * 1. Action uses AsCompensatable trait (marker)
 * 2. CompensationDesignPattern recognizes the trait
 * 3. ActionManager wraps action with CompensationDecorator
 * 4. When handle() is called, the decorator:
 *    - Executes the action
 *    - Stores compensation data for potential rollback
 *    - Tracks actions in a compensation stack
 * 5. If compensation is needed, call compensate() to rollback
 *
 * Features:
 * - Automatic compensation data tracking
 * - Compensation stack management
 * - Reverse-order compensation (LIFO)
 * - Saga pattern support
 * - Works with ActionManager's decorator system
 * - Can be composed with other decorators
 * - No trait conflicts (marker trait only)
 *
 * Benefits:
 * - Enables distributed transaction rollback
 * - Supports saga orchestration patterns
 * - Prevents partial state corruption
 * - Composable with other decorators
 * - No trait method conflicts
 *
 * Use Cases:
 * - Distributed transactions
 * - Multi-step workflows
 * - Order processing
 * - Inventory management
 * - Payment processing
 * - Resource allocation
 * - Event sourcing rollback
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * CompensationDecorator, which automatically wraps actions and adds compensation support.
 * This follows the same pattern as AsDebounced, AsCostTracked, AsLock, and other
 * decorator-based concerns.
 *
 * Configuration:
 * - Implement `compensate(...$arguments)` method to define rollback logic
 * - Optionally implement `getCompensationData(...$arguments)` to customize data storage
 *
 * @example
 * // ============================================
 * // Example 1: Basic Inventory Compensation
 * // ============================================
 * class ReserveInventory extends Actions
 * {
 *     use AsCompensatable;
 *
 *     public function handle(Product $product, int $quantity): void
 *     {
 *         $product->decrement('stock', $quantity);
 *     }
 *
 *     public function compensate(Product $product, int $quantity): void
 *     {
 *         $product->increment('stock', $quantity);
 *     }
 * }
 *
 * // Usage in saga:
 * try {
 *     ReserveInventory::run($product, 5);
 *     // ... other actions
 * } catch (\Exception $e) {
 *     ReserveInventory::compensate($product, 5); // Rollback
 * }
 * @example
 * // ============================================
 * // Example 2: Payment Processing Saga
 * // ============================================
 * class ChargePayment extends Actions
 * {
 *     use AsCompensatable;
 *
 *     public function handle(Payment $payment, float $amount): Payment
 *     {
 *         $payment->charge($amount);
 *         $payment->save();
 *
 *         return $payment;
 *     }
 *
 *     public function compensate(Payment $payment, float $amount): void
 *     {
 *         $payment->refund($amount);
 *         $payment->save();
 *     }
 * }
 *
 * class SendConfirmationEmail extends Actions
 * {
 *     use AsCompensatable;
 *
 *     public function handle(Order $order): void
 *     {
 *         Mail::to($order->user)->send(new OrderConfirmation($order));
 *     }
 *
 *     public function compensate(Order $order): void
 *     {
 *         // Email already sent - can't undo, but log for manual follow-up
 *         Log::warning("Order confirmation email sent but order was cancelled", [
 *             'order_id' => $order->id,
 *         ]);
 *     }
 * }
 *
 * // Saga orchestration:
 * $compensationStack = [];
 * try {
 *     $payment = ChargePayment::run($payment, 100.00);
 *     $compensationStack = array_merge($compensationStack, ChargePayment::getCompensationStack());
 *
 *     SendConfirmationEmail::run($order);
 *     $compensationStack = array_merge($compensationStack, SendConfirmationEmail::getCompensationStack());
 * } catch (\Exception $e) {
 *     // Compensate all actions in reverse order
 *     CompensationDecorator::compensateAllFromStack($compensationStack);
 *     throw $e;
 * }
 * @example
 * // ============================================
 * // Example 3: Order Processing with Multiple Steps
 * // ============================================
 * class CreateOrder extends Actions
 * {
 *     use AsCompensatable;
 *
 *     public function handle(User $user, array $items): Order
 *     {
 *         $order = Order::create([
 *             'user_id' => $user->id,
 *             'status' => 'pending',
 *         ]);
 *
 *         foreach ($items as $item) {
 *             $order->items()->create($item);
 *         }
 *
 *         return $order;
 *     }
 *
 *     public function compensate(Order $order): void
 *     {
 *         $order->items()->delete();
 *         $order->delete();
 *     }
 * }
 *
 * class ReserveInventory extends Actions
 * {
 *     use AsCompensatable;
 *
 *     public function handle(Order $order): void
 *     {
 *         foreach ($order->items as $item) {
 *             $product = Product::find($item->product_id);
 *             $product->decrement('stock', $item->quantity);
 *         }
 *     }
 *
 *     public function compensate(Order $order): void
 *     {
 *         foreach ($order->items as $item) {
 *             $product = Product::find($item->product_id);
 *             $product->increment('stock', $item->quantity);
 *         }
 *     }
 * }
 *
 * // Saga:
 * $compensationStack = [];
 * try {
 *     $order = CreateOrder::run($user, $items);
 *     $compensationStack[] = ['action' => CreateOrder::class, 'arguments' => [$order]];
 *
 *     ReserveInventory::run($order);
 *     $compensationStack[] = ['action' => ReserveInventory::class, 'arguments' => [$order]];
 * } catch (\Exception $e) {
 *     CompensationDecorator::compensateAllFromStack($compensationStack);
 * }
 * @example
 * // ============================================
 * // Example 4: Custom Compensation Data
 * // ============================================
 * class AllocateResource extends Actions
 * {
 *     use AsCompensatable;
 *
 *     public function handle(Resource $resource, User $user): Allocation
 *     {
 *         $allocation = Allocation::create([
 *             'resource_id' => $resource->id,
 *             'user_id' => $user->id,
 *             'allocated_at' => now(),
 *         ]);
 *
 *         $resource->update(['status' => 'allocated']);
 *
 *         return $allocation;
 *     }
 *
 *     public function compensate(Resource $resource, User $user): void
 *     {
 *         Allocation::where('resource_id', $resource->id)
 *             ->where('user_id', $user->id)
 *             ->delete();
 *
 *         $resource->update(['status' => 'available']);
 *     }
 *
 *     // Customize what data is stored for compensation
 *     protected function getCompensationData(Resource $resource, User $user): array
 *     {
 *         return [
 *             'resource_id' => $resource->id,
 *             'user_id' => $user->id,
 *             'previous_status' => $resource->status,
 *         ];
 *     }
 * }
 * @example
 * // ============================================
 * // Example 5: Database Transaction Compensation
 * // ============================================
 * class CreateUserAccount extends Actions
 * {
 *     use AsCompensatable;
 *
 *     public function handle(string $email, string $password): User
 *     {
 *         $user = User::create([
 *             'email' => $email,
 *             'password' => Hash::make($password),
 *         ]);
 *
 *         return $user;
 *     }
 *
 *     public function compensate(User $user): void
 *     {
 *         $user->delete();
 *     }
 * }
 *
 * class CreateUserProfile extends Actions
 * {
 *     use AsCompensatable;
 *
 *     public function handle(User $user, array $profileData): Profile
 *     {
 *         return $user->profile()->create($profileData);
 *     }
 *
 *     public function compensate(User $user): void
 *     {
 *         $user->profile()->delete();
 *     }
 * }
 *
 * // Saga with transaction-like behavior:
 * $compensationStack = [];
 * try {
 *     $user = CreateUserAccount::run($email, $password);
 *     $compensationStack[] = ['action' => CreateUserAccount::class, 'arguments' => [$user]];
 *
 *     CreateUserProfile::run($user, $profileData);
 *     $compensationStack[] = ['action' => CreateUserProfile::class, 'arguments' => [$user]];
 * } catch (\Exception $e) {
 *     CompensationDecorator::compensateAllFromStack($compensationStack);
 *     throw $e;
 * }
 * @example
 * // ============================================
 * // Example 6: External API Call Compensation
 * // ============================================
 * class CreateSubscription extends Actions
 * {
 *     use AsCompensatable;
 *
 *     public function handle(User $user, string $planId): Subscription
 *     {
 *         // Call external subscription service
 *         $response = Http::post('https://api.subscription.com/subscriptions', [
 *             'user_id' => $user->id,
 *             'plan_id' => $planId,
 *         ]);
 *
 *         $subscription = Subscription::create([
 *             'user_id' => $user->id,
 *             'plan_id' => $planId,
 *             'external_id' => $response->json('id'),
 *         ]);
 *
 *         return $subscription;
 *     }
 *
 *     public function compensate(Subscription $subscription): void
 *     {
 *         // Cancel subscription in external service
 *         Http::delete("https://api.subscription.com/subscriptions/{$subscription->external_id}");
 *
 *         $subscription->delete();
 *     }
 * }
 * @example
 * // ============================================
 * // Example 7: File System Operations
 * // ============================================
 * class GenerateReportFile extends Actions
 * {
 *     use AsCompensatable;
 *
 *     public function handle(Report $report): string
 *     {
 *         $filePath = storage_path("reports/{$report->id}.pdf");
 *         PDF::generate($report)->save($filePath);
 *
 *         return $filePath;
 *     }
 *
 *     public function compensate(string $filePath): void
 *     {
 *         if (file_exists($filePath)) {
 *             unlink($filePath);
 *         }
 *     }
 * }
 * @example
 * // ============================================
 * // Example 8: Cache Invalidation Compensation
 * // ============================================
 * class InvalidateCache extends Actions
 * {
 *     use AsCompensatable;
 *
 *     public function handle(string $key): void
 *     {
 *         $oldValue = Cache::get($key);
 *         Cache::forget($key);
 *
 *         // Store old value for compensation
 *         $this->setCompensationData(['key' => $key, 'old_value' => $oldValue]);
 *     }
 *
 *     public function compensate(string $key, mixed $oldValue): void
 *     {
 *         if ($oldValue !== null) {
 *             Cache::put($key, $oldValue);
 *         }
 *     }
 *
 *     protected function getCompensationData(string $key): array
 *     {
 *         return [
 *             'key' => $key,
 *             'old_value' => Cache::get($key),
 *         ];
 *     }
 * }
 * @example
 * // ============================================
 * // Example 9: Queue Job Compensation
 * // ============================================
 * class DispatchNotification extends Actions
 * {
 *     use AsCompensatable;
 *
 *     public function handle(User $user, string $message): void
 *     {
 *         SendNotificationJob::dispatch($user, $message);
 *     }
 *
 *     public function compensate(User $user, string $message): void
 *     {
 *         // Can't undo a queued job, but we can dispatch a cancellation
 *         CancelNotificationJob::dispatch($user, $message);
 *     }
 * }
 * @example
 * // ============================================
 * // Example 10: Complex Multi-Service Saga
 * // ============================================
 * class BookFlight extends Actions
 * {
 *     use AsCompensatable;
 *
 *     public function handle(Flight $flight, User $user): Booking
 *     {
 *         $booking = Booking::create([
 *             'flight_id' => $flight->id,
 *             'user_id' => $user->id,
 *             'status' => 'confirmed',
 *         ]);
 *
 *         $flight->decrement('available_seats', 1);
 *
 *         return $booking;
 *     }
 *
 *     public function compensate(Booking $booking): void
 *     {
 *         $booking->flight->increment('available_seats', 1);
 *         $booking->delete();
 *     }
 * }
 *
 * class BookHotel extends Actions
 * {
 *     use AsCompensatable;
 *
 *     public function handle(Hotel $hotel, User $user, array $dates): Reservation
 *     {
 *         $reservation = Reservation::create([
 *             'hotel_id' => $hotel->id,
 *             'user_id' => $user->id,
 *             'check_in' => $dates['check_in'],
 *             'check_out' => $dates['check_out'],
 *         ]);
 *
 *         return $reservation;
 *     }
 *
 *     public function compensate(Reservation $reservation): void
 *     {
 *         $reservation->delete();
 *     }
 * }
 *
 * // Complete travel booking saga:
 * $compensationStack = [];
 * try {
 *     $booking = BookFlight::run($flight, $user);
 *     $compensationStack[] = ['action' => BookFlight::class, 'arguments' => [$booking]];
 *
 *     $reservation = BookHotel::run($hotel, $user, $dates);
 *     $compensationStack[] = ['action' => BookHotel::class, 'arguments' => [$reservation]];
 *
 *     // If hotel booking fails, flight booking is automatically compensated
 * } catch (\Exception $e) {
 *     CompensationDecorator::compensateAllFromStack($compensationStack);
 *     Log::error('Travel booking failed, all reservations cancelled', ['error' => $e->getMessage()]);
 *     throw $e;
 * }
 */
trait AsCompensatable
{
    /**
     * Reference to the compensation decorator (injected by decorator).
     */
    protected ?CompensationDecorator $_compensationDecorator = null;

    /**
     * Set the compensation decorator reference.
     *
     * Called by CompensationDecorator to inject itself.
     */
    public function setCompensationDecorator(CompensationDecorator $decorator): void
    {
        $this->_compensationDecorator = $decorator;
    }

    /**
     * Get the compensation decorator.
     */
    protected function getCompensationDecorator(): ?CompensationDecorator
    {
        return $this->_compensationDecorator;
    }

    /**
     * Execute compensation for this action with given arguments.
     *
     * @param  mixed  ...$arguments  The arguments to pass to the compensate method
     */
    public static function compensate(...$arguments): void
    {
        $instance = static::make();

        if (method_exists($instance, 'compensate')) {
            $instance->compensate(...$arguments);
        }
    }

    /**
     * Compensate all actions from a given compensation stack.
     *
     * @param  array<int, array{action: string, data?: mixed, arguments: array}>  $compensationStack
     */
    public static function compensateAll(array $compensationStack): void
    {
        CompensationDecorator::compensateAllFromStack($compensationStack);
    }

    /**
     * Get the compensation stack from the decorator.
     *
     * @return array<int, array{action: string, data: mixed, arguments: array}>
     */
    public function getCompensationStack(): array
    {
        $decorator = $this->getCompensationDecorator();
        if ($decorator) {
            return $decorator->getCompensationStack();
        }

        return [];
    }

    /**
     * Clear the compensation stack.
     */
    public function clearCompensationStack(): void
    {
        $decorator = $this->getCompensationDecorator();
        if ($decorator) {
            $decorator->clearCompensationStack();
        }
    }
}
