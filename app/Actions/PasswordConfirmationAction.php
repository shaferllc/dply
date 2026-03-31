<?php

declare(strict_types=1);

namespace App\Actions;

use App\Actions\Tests\PasswordConfirmationActionTest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Password Confirmation Helper
 *
 * Provides password verification functionality for sensitive operations.
 * This helper validates passwords with rate limiting and error handling.
 *
 * ## Purpose
 *
 * Password confirmation adds an extra layer of security for sensitive operations like:
 * - Disabling two-factor authentication
 * - Logging out other browser sessions
 * - Downloading private SSH keys
 * - Deleting sensitive data
 * - Changing security settings
 *
 * ## Features
 *
 * - **Password Verification**: Validates against the current user's password
 * - **Rate Limiting**: Prevents brute force attempts (configurable)
 * - **User Feedback**: Clear error messages
 *
 * ## Usage Example
 *
 * In your Livewire component:
 * ```php
 * public function confirmAction(): void
 * {
 *     $result = PasswordConfirmationAction::verify(
 *         password: $this->password,
 *         maxAttempts: 3,
 *         decaySeconds: 60
 *     );
 *
 *     if ($result['success']) {
 *         // Perform sensitive action
 *         $this->dispatch('notify', type: 'success', message: 'Action completed');
 *     } else {
 *         $this->dispatch('notify', type: 'error', message: $result['error']);
 *     }
 * }
 * ```
 *
 * ## Testing
 *
 * {@see PasswordConfirmationActionTest}
 */
class PasswordConfirmationAction
{
    /**
     * Verify user password with rate limiting.
     *
     * @param  string  $password  Password to verify
     * @param  int  $maxAttempts  Maximum attempts before rate limiting (default: 3)
     * @param  int  $decaySeconds  Cooldown seconds after max attempts (default: 60)
     * @return array{success: bool, error: string|null, remainingSeconds: int|null}
     */
    public static function verify(
        string $password,
        int $maxAttempts = 3,
        int $decaySeconds = 60
    ): array {
        $user = Auth::user();

        if (! $user) {
            return [
                'success' => false,
                'error' => 'User not authenticated',
                'remainingSeconds' => null,
            ];
        }

        $throttleKey = 'password-confirmation:'.$user->id;

        // Check rate limiting
        if (RateLimiter::tooManyAttempts($throttleKey, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return [
                'success' => false,
                'error' => "Too many attempts. Please try again in {$seconds} seconds.",
                'remainingSeconds' => $seconds,
            ];
        }

        // Verify password
        if (! Hash::check($password, $user->password)) {
            RateLimiter::hit($throttleKey, $decaySeconds);

            return [
                'success' => false,
                'error' => 'Password incorrect. Please try again.',
                'remainingSeconds' => null,
            ];
        }

        // Reset rate limiter on successful attempt
        RateLimiter::clear($throttleKey);

        return [
            'success' => true,
            'error' => null,
            'remainingSeconds' => null,
        ];
    }

    /**
     * Clear rate limiting for the current user.
     */
    public static function clearRateLimit(): void
    {
        $user = Auth::user();

        if ($user) {
            $throttleKey = 'password-confirmation:'.$user->id;
            RateLimiter::clear($throttleKey);
        }
    }
}
