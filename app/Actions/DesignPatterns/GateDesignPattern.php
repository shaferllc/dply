<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsGate;
use App\Actions\Decorators\GateDecorator;
use Illuminate\Contracts\Auth\Access\Gate;

/**
 * Recognizes when actions are used as authorization gates.
 *
 * @example
 * // Action class:
 * class ViewReportsGate extends Actions
 * {
 *     use AsGate;
 *
 *     public function handle(User $user): bool
 *     {
 *         return $user->hasPermission('view-reports');
 *     }
 * }
 *
 * // Register in AuthServiceProvider:
 * Gate::define('view-reports', ViewReportsGate::class);
 *
 * // Usage:
 * if (Gate::allows('view-reports')) {
 *     // User can view reports
 * }
 *
 * // The design pattern automatically recognizes when the action
 * // is used as a gate and decorates it appropriately.
 */
class GateDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsGate::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        return $frame->instanceOf(Gate::class)
            || $frame->matches(Gate::class, 'define')
            || $frame->matches(Gate::class, 'allows')
            || $frame->matches(Gate::class, 'denies');
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(GateDecorator::class, ['action' => $instance]);
    }
}
