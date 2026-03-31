<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsPermission;
use App\Actions\Decorators\PermissionDecorator;

/**
 * Recognizes when actions use permission checking capabilities.
 *
 * @example
 * // Action class:
 * class DeleteUser extends Actions
 * {
 *     use AsPermission;
 *
 *     public function handle(User $user): void
 *     {
 *         $user->delete();
 *     }
 *
 *     public function getRequiredPermissions(): array
 *     {
 *         return ['users.delete'];
 *     }
 * }
 *
 * // Usage:
 * DeleteUser::run($user);
 * // Automatically checks user permissions before execution
 * // Throws 403 if user doesn't have 'users.delete' permission
 *
 * // The design pattern automatically recognizes when the action
 * // uses AsPermission and decorates it to check permissions.
 */
class PermissionDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsPermission::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        if (app()->runningInConsole()) {
            return false;
        }

        // Always recognize actions that use AsPermission trait
        // The decorator will handle permission checking
        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(PermissionDecorator::class, ['action' => $instance]);
    }
}
