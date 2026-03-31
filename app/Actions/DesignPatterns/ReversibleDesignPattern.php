<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsReversible;
use App\Actions\Decorators\ReversibleDecorator;

/**
 * Recognizes when actions use reversal capabilities.
 *
 * @example
 * // Action class:
 * class UpdateUserRole extends Actions
 * {
 *     use AsReversible;
 *
 *     public function handle(User $user, string $newRole): void
 *     {
 *         $oldRole = $user->role;
 *         $user->update(['role' => $newRole]);
 *
 *         // Store reversal data
 *         $this->setReversalData(['old_role' => $oldRole, 'user_id' => $user->id]);
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
 * // $result->_reversible = ['reversal_id' => '...', 'reversible' => true, 'reversed' => false]
 *
 * // Later, reverse the action:
 * UpdateUserRole::reverseById($result->_reversible['reversal_id']);
 *
 * // The design pattern automatically recognizes when the action
 * // uses AsReversible and decorates it to track reversal data.
 */
class ReversibleDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsReversible::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        if (app()->runningInConsole()) {
            return false;
        }

        // Always recognize actions that use AsReversible trait
        // The decorator will handle reversal tracking
        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(ReversibleDecorator::class, ['action' => $instance]);
    }
}
