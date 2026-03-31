<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsPolicy;
use App\Actions\Decorators\PolicyDecorator;
use Illuminate\Contracts\Auth\Access\Gate;

/**
 * Recognizes when actions are used as authorization policies.
 *
 * @example
 * // Action class:
 * class PostPolicy extends Actions
 * {
 *     use AsPolicy;
 *
 *     public function update(User $user, Post $post): bool
 *     {
 *         return $user->id === $post->user_id;
 *     }
 *
 *     public function delete(User $user, Post $post): bool
 *     {
 *         return $user->id === $post->user_id || $user->isAdmin();
 *     }
 *
 *     public function before(?User $user, string $ability): ?bool
 *     {
 *         if ($user?->isAdmin()) {
 *             return true; // Admins can do everything
 *         }
 *
 *         return null; // Continue with normal policy checks
 *     }
 * }
 *
 * // Register in AuthServiceProvider:
 * protected $policies = [
 *     Post::class => PostPolicy::class,
 * ];
 *
 * // Usage:
 * Gate::authorize('update', $post);
 * // or
 * $this->authorize('update', $post);
 *
 * // The design pattern automatically recognizes when the action
 * // is used as a policy and decorates it appropriately.
 */
class PolicyDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsPolicy::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        return $frame->instanceOf(Gate::class)
            || $frame->matches(Gate::class, 'authorize')
            || $frame->matches(Gate::class, 'allows')
            || $frame->matches(Gate::class, 'denies');
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(PolicyDecorator::class, ['action' => $instance]);
    }
}
