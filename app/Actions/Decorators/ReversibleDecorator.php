<?php

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use Illuminate\Support\Facades\DB;

/**
 * Reversible Decorator
 *
 * Automatically tracks action execution for reversal/undo capabilities.
 * This decorator intercepts handle() calls and stores reversal data
 * that can be used to undo the action later.
 *
 * Features:
 * - Automatic reversal ID generation
 * - Stores reversal data in database
 * - Supports reverse() and undo() methods
 * - Can reverse actions by ID
 * - Tracks reversal status
 * - Adds reversal metadata to results
 *
 * How it works:
 * 1. When an action uses AsReversible, ReversibleDesignPattern recognizes it
 * 2. ActionManager wraps the action with ReversibleDecorator
 * 3. When handle() is called, the decorator:
 *    - Generates a unique reversal ID
 *    - Executes the action
 *    - Stores reversal data if provided by action
 *    - Adds reversal metadata to result
 * 4. Actions can be reversed using reverse() or reverseById()
 *
 * Reversal Metadata:
 * The result will include a `_reversible` property with:
 * - `reversal_id`: Unique identifier for this execution
 * - `reversible`: Whether reversal data was stored
 * - `reversed`: Whether this action has been reversed
 */
class ReversibleDecorator
{
    use DecorateActions;

    protected ?string $reversalId = null;

    protected ?array $reversalData = null;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    /**
     * Execute the action and track reversal data.
     *
     * @param  mixed  ...$arguments
     * @return mixed
     */
    public function handle(...$arguments)
    {
        $this->reversalId = $this->generateReversalId();

        // Set reversal ID on action so it can be accessed in handle()
        if ($this->hasProperty('reversalId')) {
            $this->setProperty('reversalId', $this->reversalId);
        }

        // Execute the action
        $result = $this->action->handle(...$arguments);

        // Get reversal data from action if it was set via setReversalData()
        if ($this->hasProperty('reversalData')) {
            $this->reversalData = $this->getProperty('reversalData');
        }

        // Store reversal data if provided
        if ($this->reversalData !== null) {
            $this->storeReversalData();
        }

        // Add reversal metadata to result
        if (is_object($result)) {
            $result->_reversible = [
                'reversal_id' => $this->reversalId,
                'reversible' => $this->reversalData !== null,
                'reversed' => false,
            ];
        }

        return $result;
    }

    /**
     * Make the decorator callable.
     *
     * @param  mixed  ...$arguments
     * @return mixed
     */
    public function __invoke(...$arguments)
    {
        return $this->handle(...$arguments);
    }

    /**
     * Store reversal data in database.
     */
    protected function storeReversalData(): void
    {
        if (! $this->reversalId || ! $this->reversalData) {
            return;
        }

        DB::table('action_reversals')->insert([
            'reversal_id' => $this->reversalId,
            'action_class' => get_class($this->action),
            'data' => json_encode($this->reversalData),
            'created_at' => now(),
        ]);
    }

    /**
     * Generate a unique reversal ID.
     */
    protected function generateReversalId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Get the reversal ID for this execution.
     * Also sets it on the action so it can be accessed.
     */
    public function getReversalId(): ?string
    {
        // Also set on action for access
        if ($this->hasProperty('reversalId')) {
            $this->setProperty('reversalId', $this->reversalId);
        }

        return $this->reversalId;
    }
}
