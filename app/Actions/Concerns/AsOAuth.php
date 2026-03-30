<?php

namespace App\Actions\Concerns;

use App\Actions\Decorators\OAuthDecorator;
use App\Actions\DesignPatterns\OAuthDesignPattern;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Requires specific OAuth scope(s) before action execution.
 *
 * Provides OAuth scope checking capabilities for actions, preventing unauthorized
 * access by verifying user OAuth scopes before action execution. Throws 403
 * exceptions when required scopes are not met.
 *
 * How it works:
 * - OAuthDesignPattern recognizes actions using AsOAuth
 * - ActionManager wraps the action with OAuthDecorator
 * - When handle() is called, the decorator:
 *    - Gets required scopes from action
 *    - Gets current user's OAuth scopes (from token or session)
 *    - Checks if user has ALL required scopes (AND logic)
 *    - Throws 403 exception if insufficient scopes
 *    - Executes the action if authorized
 *
 * Benefits:
 * - Automatic OAuth scope validation
 * - Support for multiple scopes (AND logic)
 * - Works with Laravel Sanctum, Passport, and custom OAuth
 * - Custom unauthorized handling
 * - Multiple OAuth provider support
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * OAuthDecorator, which automatically wraps actions and checks OAuth scopes.
 * This follows the same pattern as AsPermission, AsTimeout, and other
 * decorator-based concerns.
 *
 * OAuth Scope Sources:
 * - Laravel Sanctum: Token scopes via $user->token()->scopes() or $user->currentAccessToken()->scopes()
 * - Laravel Passport: Token scopes via $user->token()->scopes()
 * - Custom: Session storage via 'oauth_scopes_{user_id}' key
 *
 * @example
 * // ============================================
 * // Example 1: Basic Usage - Single Scope
 * // ============================================
 * class ReadUserData extends Actions
 * {
 *     use AsOAuth;
 *
 *     public function handle(User $user): array
 *     {
 *         return ['data' => $user->toArray()];
 *     }
 *
 *     public function getRequiredScopes(): array
 *     {
 *         return ['read'];
 *     }
 * }
 *
 * // Usage:
 * ReadUserData::run($user);
 * // Automatically checks if user has 'read' scope
 * // Throws 403 if user doesn't have 'read' scope
 * @example
 * // ============================================
 * // Example 2: Multiple Scopes (AND Logic)
 * // ============================================
 * class ManageUserData extends Actions
 * {
 *     use AsOAuth;
 *
 *     public function handle(User $user, array $data): User
 *     {
 *         $user->update($data);
 *         return $user->fresh();
 *     }
 *
 *     public function getRequiredScopes(): array
 *     {
 *         return ['read', 'write'];
 *     }
 * }
 *
 * // Usage:
 * ManageUserData::run($user, ['name' => 'John']);
 * // User must have BOTH 'read' AND 'write' scopes
 * // Throws 403 if user is missing either scope
 * @example
 * // ============================================
 * // Example 3: Using Properties for Configuration
 * // ============================================
 * class ProcessOrder extends Actions
 * {
 *     use AsOAuth;
 *
 *     // Configure via properties
 *     public array $requiredScopes = ['orders.read', 'orders.write'];
 *     public string $oauthProvider = 'stripe';
 *
 *     public function handle(Order $order): void
 *     {
 *         // Process order
 *     }
 * }
 *
 * // Usage:
 * $action = ProcessOrder::make();
 * $action->requiredScopes = ['orders.read', 'orders.write', 'orders.delete'];
 * $action->handle($order);
 * @example
 * // ============================================
 * // Example 4: Custom Insufficient Scopes Handling
 * // ============================================
 * class RestrictedAction extends Actions
 * {
 *     use AsOAuth;
 *
 *     public function handle(): void
 *     {
 *         // Restricted operation
 *     }
 *
 *     public function getRequiredScopes(): array
 *     {
 *         return ['admin.access'];
 *     }
 *
 *     public function handleInsufficientScopes(array $requiredScopes, array $userScopes): void
 *     {
 *         // Custom handling instead of default 403
 *         \Log::warning('Insufficient OAuth scopes', [
 *             'user_id' => auth()->id(),
 *             'required' => $requiredScopes,
 *             'user_scopes' => $userScopes,
 *         ]);
 *
 *         // Redirect to upgrade page
 *         redirect()->route('oauth.upgrade')->send();
 *     }
 * }
 *
 * // Usage:
 * RestrictedAction::run();
 * // Custom insufficient scopes handling is called instead of default 403
 * @example
 * // ============================================
 * // Example 5: OAuth with Laravel Sanctum
 * // ============================================
 * // Works automatically with Laravel Sanctum tokens
 * class SanctumProtectedAction extends Actions
 * {
 *     use AsOAuth;
 *
 *     public function handle(): array
 *     {
 *         return ['data' => 'protected'];
 *     }
 *
 *     public function getRequiredScopes(): array
 *     {
 *         return ['read', 'write'];
 *     }
 * }
 *
 * // Usage with Sanctum token:
 * // Token must have 'read' and 'write' scopes
 * SanctumProtectedAction::run();
 * // Automatically uses $user->currentAccessToken()->scopes()
 * @example
 * // ============================================
 * // Example 6: OAuth with Laravel Passport
 * // ============================================
 * // Works automatically with Laravel Passport tokens
 * class PassportProtectedAction extends Actions
 * {
 *     use AsOAuth;
 *
 *     public function handle(): array
 *     {
 *         return ['data' => 'protected'];
 *     }
 *
 *     public function getRequiredScopes(): array
 *     {
 *         return ['read'];
 *     }
 * }
 *
 * // Usage with Passport token:
 * // Token must have 'read' scope
 * PassportProtectedAction::run();
 * // Automatically uses $user->token()->scopes()
 * @example
 * // ============================================
 * // Example 7: OAuth with Session Storage
 * // ============================================
 * // For custom OAuth implementations, store scopes in session
 * class SessionOAuthAction extends Actions
 * {
 *     use AsOAuth;
 *
 *     public function handle(): array
 *     {
 *         return ['data' => 'protected'];
 *     }
 *
 *     public function getRequiredScopes(): array
 *     {
 *         return ['custom.scope'];
 *     }
 * }
 *
 * // In your OAuth callback:
 * session(['oauth_scopes_'.auth()->id() => ['custom.scope', 'another.scope']]);
 *
 * // Usage:
 * SessionOAuthAction::run();
 * // Automatically retrieves scopes from session
 * @example
 * // ============================================
 * // Example 8: Dynamic Scopes Based on Context
 * // ============================================
 * class ContextualOAuthAction extends Actions
 * {
 *     use AsOAuth;
 *
 *     public function handle(Team $team): void
 *     {
 *         // Team-specific operation
 *     }
 *
 *     public function getRequiredScopes(): array
 *     {
 *         // Dynamic scopes based on team
 *         $team = $this->getTeamFromArguments();
 *
 *         return match ($team->tier) {
 *             'enterprise' => ['teams.enterprise.read', 'teams.enterprise.write'],
 *             'professional' => ['teams.professional.read', 'teams.professional.write'],
 *             default => ['teams.basic.read'],
 *         };
 *     }
 *
 *     protected function getTeamFromArguments(): ?Team
 *     {
 *         // Extract team from arguments
 *         return null; // Implementation
 *     }
 * }
 * @example
 * // ============================================
 * // Example 9: OAuth in API Endpoints
 * // ============================================
 * class ApiEndpoint extends Actions
 * {
 *     use AsOAuth;
 *
 *     public function handle(Request $request): array
 *     {
 *         return ['data' => 'processed'];
 *     }
 *
 *     public function getRequiredScopes(): array
 *     {
 *         return ['api.access'];
 *     }
 *
 *     public function handleInsufficientScopes(array $requiredScopes, array $userScopes): void
 *     {
 *         // API-specific error response
 *         abort(403, [
 *             'error' => 'insufficient_scopes',
 *             'message' => 'The request requires higher privileges.',
 *             'required_scopes' => $requiredScopes,
 *             'user_scopes' => $userScopes,
 *         ]);
 *     }
 * }
 *
 * // Usage:
 * ApiEndpoint::run($request);
 * // Returns JSON error response for API requests
 * @example
 * // ============================================
 * // Example 10: OAuth with Multiple Providers
 * // ============================================
 * class MultiProviderAction extends Actions
 * {
 *     use AsOAuth;
 *
 *     public function handle(): void
 *     {
 *         // Multi-provider operation
 *     }
 *
 *     public function getRequiredScopes(): array
 *     {
 *         return ['read', 'write'];
 *     }
 *
 *     public function getOAuthProvider(): string
 *     {
 *         // Determine provider based on context
 *         return request()->header('X-OAuth-Provider', 'default');
 *     }
 * }
 *
 * // Usage:
 * MultiProviderAction::run();
 * // Can work with different OAuth providers
 * @example
 * // ============================================
 * // Example 11: Combining with Other Decorators
 * // ============================================
 * class ComprehensiveOAuthAction extends Actions
 * {
 *     use AsOAuth;
 *     use AsRetry;
 *     use AsTimeout;
 *     use AsValidated;
 *
 *     public function handle(array $data): void
 *     {
 *         // Operation that needs OAuth, retry, timeout, and validation
 *     }
 *
 *     public function getRequiredScopes(): array
 *     {
 *         return ['comprehensive.action'];
 *     }
 * }
 *
 * // Usage:
 * ComprehensiveOAuthAction::run(['key' => 'value']);
 * // Combines OAuth scope checking, retry, timeout, and validation decorators
 * @example
 * // ============================================
 * // Example 12: OAuth in Livewire Components
 * // ============================================
 * class LivewireOAuthAction extends Actions
 * {
 *     use AsOAuth;
 *
 *     public function handle(): void
 *     {
 *         // Livewire operation
 *     }
 *
 *     public function getRequiredScopes(): array
 *     {
 *         return ['livewire.action'];
 *     }
 * }
 *
 * // Livewire Component:
 * class LivewireComponent extends Component
 * {
 *     public function performAction(): void
 *     {
 *         LivewireOAuthAction::run();
 *         // OAuth scope checking works seamlessly with Livewire
 *     }
 *
 *     public function render(): View
 *     {
 *         return view('livewire.component');
 *     }
 * }
 * @example
 * // ============================================
 * // Example 13: No Required Scopes (Optional Check)
 * // ============================================
 * class OptionalOAuthAction extends Actions
 * {
 *     use AsOAuth;
 *
 *     public function handle(): array
 *     {
 *         return ['data' => 'optional oauth'];
 *     }
 *
 *     public function getRequiredScopes(): array
 *     {
 *         // Return empty array to skip scope checking
 *         return [];
 *     }
 * }
 *
 * // Usage:
 * OptionalOAuthAction::run();
 * // No scope checking is performed, action executes normally
 * @example
 * // ============================================
 * // Example 14: Real-World Usage - GitHub API Integration
 * // ============================================
 * class FetchGitHubRepos extends Actions
 * {
 *     use AsOAuth;
 *
 *     public function handle(User $user): array
 *     {
 *         // Fetch repos from GitHub API
 *         return ['repos' => []];
 *     }
 *
 *     public function getRequiredScopes(): array
 *     {
 *         return ['repo', 'read:user'];
 *     }
 *
 *     public function getOAuthProvider(): string
 *     {
 *         return 'github';
 *     }
 * }
 *
 * // Usage:
 * FetchGitHubRepos::run($user);
 * // Requires 'repo' and 'read:user' scopes from GitHub OAuth token
 * @example
 * // ============================================
 * // Example 15: Real-World Usage - Stripe Integration
 * // ============================================
 * class ProcessStripePayment extends Actions
 * {
 *     use AsOAuth;
 *
 *     public function handle(array $paymentData): Payment
 *     {
 *         // Process payment via Stripe
 *         return Payment::create($paymentData);
 *     }
 *
 *     public function getRequiredScopes(): array
 *     {
 *         return ['payments.write', 'payments.read'];
 *     }
 *
 *     public function getOAuthProvider(): string
 *     {
 *         return 'stripe';
 *     }
 * }
 *
 * // Usage:
 * ProcessStripePayment::run(['amount' => 1000, 'currency' => 'usd']);
 * // Requires Stripe OAuth scopes for payment processing
 *
 * @see OAuthDecorator
 * @see OAuthDesignPattern
 */
trait AsOAuth
{
    /**
     * Get required OAuth scopes for this action.
     * Override this method to define required scopes.
     */
    protected function getRequiredScopes(): array
    {
        if (property_exists($this, 'requiredScopes')) {
            return (array) $this->requiredScopes;
        }

        return [];
    }

    /**
     * Get OAuth provider name for this action.
     * Override this method to specify a custom provider.
     */
    protected function getOAuthProvider(): string
    {
        if (property_exists($this, 'oauthProvider')) {
            return (string) $this->oauthProvider;
        }

        return 'default';
    }

    /**
     * Handle insufficient OAuth scopes.
     * Override this method for custom insufficient scopes handling.
     *
     * @param  array  $requiredScopes  The scopes that were required
     * @param  array  $userScopes  The scopes the user actually has
     */
    protected function handleInsufficientScopes(array $requiredScopes, array $userScopes): void
    {
        $message = 'Insufficient OAuth scopes. Required: '.implode(', ', $requiredScopes);

        if (request()->expectsJson()) {
            throw new HttpResponseException(
                response()->json([
                    'error' => 'insufficient_scopes',
                    'message' => $message,
                    'required_scopes' => $requiredScopes,
                    'user_scopes' => $userScopes,
                ], 403)
            );
        }

        abort(403, $message);
    }
}
