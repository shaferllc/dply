<?php

namespace App\Actions\DesignPatterns;

use App\Actions\BacktraceFrame;
use App\Actions\Concerns\AsOAuth;
use App\Actions\Decorators\OAuthDecorator;

/**
 * Recognizes when actions use OAuth authentication and authorization.
 *
 * @example
 * // Action class:
 * class OAuthAction extends Actions
 * {
 *     use AsOAuth;
 *
 *     public function handle(): array
 *     {
 *         return ['data' => 'oauth protected'];
 *     }
 *
 *     public function getRequiredScopes(): array
 *     {
 *         return ['read', 'write'];
 *     }
 * }
 *
 * // Usage:
 * OAuthAction::run();
 * // Automatically validates OAuth tokens and scopes before execution
 * // Throws 403 if user doesn't have required scopes
 *
 * // The design pattern automatically recognizes when the action
 * // uses AsOAuth and decorates it to check OAuth scopes.
 */
class OAuthDesignPattern extends DesignPattern
{
    public function getTrait(): string
    {
        return AsOAuth::class;
    }

    public function recognizeFrame(BacktraceFrame $frame): bool
    {
        if (app()->runningInConsole()) {
            return false;
        }

        // Always recognize actions that use AsOAuth trait
        // The decorator will handle OAuth scope checking
        return true;
    }

    public function decorate($instance, BacktraceFrame $frame)
    {
        return app(OAuthDecorator::class, ['action' => $instance]);
    }
}
