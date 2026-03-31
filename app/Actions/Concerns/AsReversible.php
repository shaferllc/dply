<?php

namespace App\Actions\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Allows actions to be reversed/undone.
 *
 * Provides reversal/undo capabilities for actions, allowing them to be
 * undone after execution. Reversal data is stored in the database and
 * can be used to restore previous state.
 *
 * How it works:
 * - ReversibleDesignPattern recognizes actions using AsReversible
 * - ActionManager wraps the action with ReversibleDecorator
 * - When handle() is called, the decorator:
 *    - Generates a unique reversal ID
 *    - Executes the action
 *    - Stores reversal data if provided by action
 *    - Adds reversal metadata to result
 * - Actions can be reversed using reverse() or reverseById()
 *
 * Benefits:
 * - Undo action execution
 * - Store reversal data for later use
 * - Track reversal status
 * - Reverse actions by ID
 * - Automatic reversal ID generation
 * - Database-backed reversal tracking
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * ReversibleDecorator, which automatically wraps actions and tracks reversal
 * data. This follows the same pattern as AsTimeout, AsThrottle, and other
 * decorator-based concerns.
 *
 * Reversal Data Storage:
 * Reversal data is stored in the `action_reversals` table with:
 * - `reversal_id`: Unique identifier
 * - `action_class`: Action class name
 * - `data`: JSON-encoded reversal data
 * - `created_at`: When the action was executed
 * - `reversed_at`: When the action was reversed (null if not reversed)
 *
 * Reversal Metadata:
 * The result will include a `_reversible` property with:
 * - `reversal_id`: Unique identifier for this execution
 * - `reversible`: Whether reversal data was stored
 * - `reversed`: Whether this action has been reversed
 *
 * @example
 * // Basic usage - reversible user role update:
 * class UpdateUserRole extends Actions
 * {
 *     use AsReversible;
 *
 *     public function handle(User $user, string $newRole): void
 *     {
 *         $oldRole = $user->role;
 *         $user->update(['role' => $newRole]);
 *
 *         // Store reversal data for undo
 *         $this->setReversalData([
 *             'user_id' => $user->id,
 *             'old_role' => $oldRole,
 *         ]);
 *     }
 *
 *     public function reverse(): void
 *     {
 *         $data = $this->getReversalData();
 *         User::find($data['user_id'])->update(['role' => $data['old_role']]);
 *     }
 * }
 *
 * // Usage:
 * $result = UpdateUserRole::run($user, 'admin');
 * // $result->_reversible = [
 * //     'reversal_id' => 'abc123...',
 * //     'reversible' => true,
 * //     'reversed' => false,
 * // ]
 *
 * // Later, reverse the action:
 * $reversalId = $result->_reversible['reversal_id'];
 * UpdateUserRole::reverseById($reversalId);
 * @example
 * // Using undo() method instead of reverse():
 * class DeleteRecord extends Actions
 * {
 *     use AsReversible;
 *
 *     public function handle(Record $record): void
 *     {
 *         // Store record data before deletion
 *         $this->setReversalData([
 *             'record_id' => $record->id,
 *             'record_data' => $record->toArray(),
 *         ]);
 *
 *         $record->delete();
 *     }
 *
 *     public function undo(): void
 *     {
 *         $data = $this->getReversalData();
 *         // Restore the deleted record
 *         Record::create($data['record_data']);
 *     }
 * }
 *
 * // Usage:
 * $result = DeleteRecord::run($record);
 * // Later, undo the deletion:
 * DeleteRecord::reverseById($result->_reversible['reversal_id']);
 * @example
 * // Complex reversal with multiple changes:
 * class TransferOwnership extends Actions
 * {
 *     use AsReversible;
 *
 *     public function handle(Project $project, User $newOwner): void
 *     {
 *         $oldOwner = $project->owner;
 *
 *         // Store all data needed for reversal
 *         $this->setReversalData([
 *             'project_id' => $project->id,
 *             'old_owner_id' => $oldOwner->id,
 *             'old_owner_name' => $oldOwner->name,
 *             'new_owner_id' => $newOwner->id,
 *             'transferred_at' => now()->toIso8601String(),
 *         ]);
 *
 *         // Transfer ownership
 *         $project->update(['owner_id' => $newOwner->id]);
 *         $project->members()->syncWithoutDetaching([$oldOwner->id]);
 *     }
 *
 *     public function reverse(): void
 *     {
 *         $data = $this->getReversalData();
 *         $project = Project::find($data['project_id']);
 *
 *         // Restore previous owner
 *         $project->update(['owner_id' => $data['old_owner_id']]);
 *         $project->members()->detach($data['new_owner_id']);
 *     }
 * }
 *
 * // Usage:
 * $result = TransferOwnership::run($project, $newOwner);
 * // Store reversal ID for later
 * $reversalId = $result->_reversible['reversal_id'];
 *
 * // Later, reverse the transfer:
 * TransferOwnership::reverseById($reversalId);
 * @example
 * // Reversing from a queue/job context:
 * class ProcessPayment extends Actions
 * {
 *     use AsReversible;
 *
 *     public function handle(Order $order, float $amount): void
 *     {
 *         // Store order state before payment
 *         $this->setReversalData([
 *             'order_id' => $order->id,
 *             'previous_balance' => $order->balance,
 *             'previous_status' => $order->status,
 *             'amount' => $amount,
 *         ]);
 *
 *         // Process payment
 *         $order->update([
 *             'balance' => $order->balance - $amount,
 *             'status' => 'paid',
 *         ]);
 *     }
 *
 *     public function reverse(): void
 *     {
 *         $data = $this->getReversalData();
 *         $order = Order::find($data['order_id']);
 *
 *         // Restore previous state
 *         $order->update([
 *             'balance' => $data['previous_balance'],
 *             'status' => $data['previous_status'],
 *         ]);
 *     }
 * }
 *
 * // Usage in a job:
 * class ProcessPaymentJob implements ShouldQueue
 * {
 *     public function handle(): void
 *     {
 *         $result = ProcessPayment::run($order, 100.00);
 *
 *         // Store reversal ID for potential refund
 *         dispatch(new StoreReversalIdJob($result->_reversible['reversal_id']));
 *     }
 * }
 * @example
 * // Conditional reversal (only store data if needed):
 * class UpdateSettings extends Actions
 * {
 *     use AsReversible;
 *
 *     public function handle(Settings $settings, array $newSettings): void
 *     {
 *         $oldSettings = $settings->toArray();
 *
 *         // Only store reversal data if important settings changed
 *         if ($this->hasImportantChanges($oldSettings, $newSettings)) {
 *             $this->setReversalData([
 *                 'settings_id' => $settings->id,
 *                 'old_settings' => $oldSettings,
 *             ]);
 *         }
 *
 *         $settings->update($newSettings);
 *     }
 *
 *     public function reverse(): void
 *     {
 *         $data = $this->getReversalData();
 *         if (! $data) {
 *             return; // Nothing to reverse
 *         }
 *
 *         Settings::find($data['settings_id'])->update($data['old_settings']);
 *     }
 *
 *     protected function hasImportantChanges(array $old, array $new): bool
 *     {
 *         // Check if critical settings changed
 *         return isset($new['critical_setting']) &&
 *                $old['critical_setting'] !== $new['critical_setting'];
 *     }
 * }
 * @example
 * // Reversal with transaction rollback:
 * class CreateOrder extends Actions
 * {
 *     use AsReversible;
 *
 *     public function handle(array $orderData): Order
 *     {
 *         DB::beginTransaction();
 *
 *         try {
 *             $order = Order::create($orderData);
 *             $order->items()->createMany($orderData['items']);
 *
 *             // Store minimal reversal data
 *             $this->setReversalData(['order_id' => $order->id]);
 *
 *             DB::commit();
 *
 *             return $order;
 *         } catch (\Exception $e) {
 *             DB::rollBack();
 *             throw $e;
 *         }
 *     }
 *
 *     public function reverse(): void
 *     {
 *         $data = $this->getReversalData();
 *
 *         DB::transaction(function () use ($data) {
 *             $order = Order::find($data['order_id']);
 *             $order->items()->delete();
 *             $order->delete();
 *         });
 *     }
 * }
 * @example
 * // Using reversal ID in API responses:
 * class ApproveRequest extends Actions
 * {
 *     use AsReversible;
 *
 *     public function handle(Request $request): Request
 *     {
 *         $this->setReversalData([
 *             'request_id' => $request->id,
 *             'previous_status' => $request->status,
 *         ]);
 *
 *         $request->update(['status' => 'approved']);
 *
 *         return $request;
 *     }
 *
 *     public function reverse(): void
 *     {
 *         $data = $this->getReversalData();
 *         Request::find($data['request_id'])
 *             ->update(['status' => $data['previous_status']]);
 *     }
 * }
 *
 * // Usage in controller:
 * class RequestController extends Controller
 * {
 *     public function approve(Request $request)
 *     {
 *         $result = ApproveRequest::run($request);
 *
 *         return response()->json([
 *             'request' => $result,
 *             'reversal_id' => $result->_reversible['reversal_id'],
 *             'can_undo' => true,
 *         ]);
 *     }
 *
 *     public function undo(Request $request, string $reversalId)
 *     {
 *         ApproveRequest::reverseById($reversalId);
 *
 *         return response()->json(['message' => 'Request approval undone']);
 *     }
 * }
 * @example
 * // Batch reversal (reversing multiple actions):
 * class BatchUpdate extends Actions
 * {
 *     use AsReversible;
 *
 *     public function handle(array $updates): array
 *     {
 *         $reversalIds = [];
 *
 *         foreach ($updates as $update) {
 *             $result = UpdateItem::run($update['item'], $update['data']);
 *             $reversalIds[] = $result->_reversible['reversal_id'];
 *         }
 *
 *         // Store all reversal IDs for batch undo
 *         $this->setReversalData(['reversal_ids' => $reversalIds]);
 *
 *         return ['updated' => count($updates)];
 *     }
 *
 *     public function reverse(): void
 *     {
 *         $data = $this->getReversalData();
 *
 *         foreach ($data['reversal_ids'] as $reversalId) {
 *             UpdateItem::reverseById($reversalId);
 *         }
 *     }
 * }
 *
 * // Usage:
 * $result = BatchUpdate::run($updates);
 * // Later, reverse all updates:
 * BatchUpdate::reverseById($result->_reversible['reversal_id']);
 */
trait AsReversible
{
    // Properties that the decorator will set and access
    protected ?array $reversalData = null;

    protected ?string $reversalId = null;

    /**
     * Set reversal data to be stored by the decorator.
     */
    protected function setReversalData(array $data): void
    {
        $this->reversalData = $data;
    }

    /**
     * Get reversal data (for use in reverse() method).
     */
    protected function getReversalData(): ?array
    {
        if ($this->reversalData !== null) {
            return $this->reversalData;
        }

        if ($this->reversalId) {
            $stored = $this->loadReversalData($this->reversalId);

            return $stored['data'] ?? null;
        }

        return null;
    }

    /**
     * Load reversal data from database (used by reverseById).
     */
    protected function loadReversalData(string $reversalId): ?array
    {
        $record = DB::table('action_reversals')
            ->where('reversal_id', $reversalId)
            ->where('action_class', static::class)
            ->first();

        if (! $record) {
            return null;
        }

        return [
            'data' => json_decode($record->data, true),
            'created_at' => $record->created_at,
            'reversed_at' => $record->reversed_at,
        ];
    }

    /**
     * Mark the action as reversed in the database.
     */
    protected function markAsReversed(): void
    {
        if ($this->reversalId) {
            DB::table('action_reversals')
                ->where('reversal_id', $this->reversalId)
                ->where('action_class', static::class)
                ->update(['reversed_at' => now()]);
        }
    }

    /**
     * Get the reversal ID for this execution.
     */
    public function getReversalId(): ?string
    {
        return $this->reversalId;
    }

    /**
     * Reverse the action.
     * This method should be overridden in your action class to implement reversal logic.
     * If not overridden, it will try to call undo() method as a fallback.
     */
    public function reverse(): void
    {
        // Check if class has its own reverse() method (not from this trait)
        $reflection = new \ReflectionClass($this);
        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

        $classReverse = null;
        foreach ($methods as $method) {
            if ($method->getName() === 'reverse') {
                $declaringClass = $method->getDeclaringClass()->getName();
                // Find reverse method declared in the actual class (not in this trait)
                if ($declaringClass !== self::class) {
                    $classReverse = $method;
                    break;
                }
            }
        }

        if ($classReverse) {
            // Call the class's reverse() method
            $classReverse->invoke($this);
        } elseif ($this->hasMethod('undo')) {
            // Fallback to undo() method
            $this->callMethod('undo');
        } else {
            throw new \RuntimeException(
                'Action does not implement reverse() or undo() method.'
            );
        }

        $this->markAsReversed();
    }

    /**
     * Reverse an action by its reversal ID.
     */
    public static function reverseById(string $reversalId): void
    {
        $instance = static::make();
        $reversalData = $instance->loadReversalData($reversalId);

        if (! $reversalData) {
            throw new \RuntimeException("Reversal data not found for ID: {$reversalId}");
        }

        // Set reversal data on instance
        $instance->reversalId = $reversalId;
        $instance->reversalData = $reversalData['data'];

        // Call reverse method
        $instance->reverse();
    }
}
