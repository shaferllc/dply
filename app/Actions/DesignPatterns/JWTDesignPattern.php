<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsJWT;
use App\Actions\Decorators\JWTDecorator;

/**
 * Recognizes when actions use JWT authentication.
 *
 * @example
 * // Action class:
 * class ApiAction extends Actions
 * {
 *     use AsJWT;
 *
 *     public function handle(): array
 *     {
 *         return ['data' => 'protected'];
 *     }
 * }
 *
 * // Usage:
 * ApiAction::run();
 * // Automatically validates JWT token from request
 *
 * // The design pattern automatically recognizes when the action
 * // uses AsJWT and decorates it to add JWT authentication.
 */
class JWTDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsJWT::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        // Always recognize actions that use AsJWT trait
        // The decorator will handle JWT authentication
        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(JWTDecorator::class, ['action' => $instance]);
    }
}
