<?php

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;

/**
 * OAuth Decorator
 *
 * Automatically validates OAuth tokens and scopes before allowing action execution.
 * This decorator intercepts handle() calls and verifies the user has required OAuth
 * scopes before executing the action.
 *
 * Features:
 * - Automatic OAuth scope validation
 * - Support for multiple scopes (AND logic - user must have ALL scopes)
 * - Custom scope retrieval from tokens or session
 * - Multiple OAuth provider support
 * - Unauthorized access handling
 * - Works with Laravel Sanctum, Passport, and custom OAuth implementations
 *
 * How it works:
 * 1. When an action uses AsOAuth, OAuthDesignPattern recognizes it
 * 2. ActionManager wraps the action with OAuthDecorator
 * 3. When handle() is called, the decorator:
 *    - Gets required scopes from action
 *    - Gets current user's OAuth scopes (from token or session)
 *    - Checks if user has ALL required scopes
 *    - Throws 403 exception if insufficient scopes
 *    - Executes the action if authorized
 *
 * OAuth Scope Sources:
 * - Laravel Sanctum: Token scopes via $user->token()->scopes()
 * - Laravel Passport: Token scopes via $user->token()->scopes()
 * - Custom: Session storage via 'oauth_scopes_{user_id}' key
 */
class OAuthDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    /**
     * Execute the action with OAuth scope checking.
     *
     * @param  mixed  ...$arguments
     * @return mixed
     *
     * @throws HttpResponseException If insufficient scopes
     */
    public function handle(...$arguments)
    {
        $requiredScopes = $this->getRequiredScopes();

        if (! empty($requiredScopes)) {
            $userScopes = $this->getUserScopes();

            if (! $this->hasRequiredScopes($userScopes, $requiredScopes)) {
                $this->handleInsufficientScopes($requiredScopes, $userScopes);
            }
        }

        // Execute the action
        return $this->action->handle(...$arguments);
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
     * Get user's OAuth scopes.
     *
     * Tries multiple methods to retrieve scopes:
     * 1. From token (Laravel Sanctum/Passport)
     * 2. From session storage
     */
    protected function getUserScopes(): array
    {
        $user = Auth::user();

        if (! $user) {
            return [];
        }

        // Try to get scopes from token (Laravel Sanctum/Passport)
        if (method_exists($user, 'token')) {
            $token = $user->token();

            if ($token && method_exists($token, 'scopes')) {
                /** @var object{scopes(): array} $token */
                return $token->scopes();
            }
        }

        // Try to get from currentAccessToken (Laravel Sanctum)
        if (method_exists($user, 'currentAccessToken')) {
            $token = $user->currentAccessToken();

            if ($token && method_exists($token, 'scopes')) {
                return call_user_func([$token, 'scopes']);
            }

            // Some token implementations use abilities property
            if ($token && property_exists($token, 'abilities')) {
                return $token->abilities ?? [];
            }
        }

        // Try to get from session
        $sessionKey = $this->getOAuthScopesSessionKey();

        return session($sessionKey, []);
    }

    /**
     * Check if user has all required scopes.
     *
     * User must have ALL required scopes (AND logic).
     */
    protected function hasRequiredScopes(array $userScopes, array $requiredScopes): bool
    {
        // User must have ALL required scopes
        return count(array_intersect($requiredScopes, $userScopes)) === count($requiredScopes);
    }

    /**
     * Handle insufficient OAuth scopes.
     *
     *
     * @throws HttpResponseException
     */
    protected function handleInsufficientScopes(array $requiredScopes, array $userScopes): void
    {
        if ($this->hasMethod('handleInsufficientScopes')) {
            $this->callMethod('handleInsufficientScopes', [$requiredScopes, $userScopes]);

            return;
        }

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

    /**
     * Get required OAuth scopes from the action.
     */
    protected function getRequiredScopes(): array
    {
        return $this->fromActionMethodOrProperty('getRequiredScopes', 'requiredScopes', []);
    }

    /**
     * Get OAuth provider name from the action.
     */
    protected function getOAuthProvider(): string
    {
        return $this->fromActionMethodOrProperty('getOAuthProvider', 'oauthProvider', 'default');
    }

    /**
     * Get session key for storing OAuth scopes.
     */
    protected function getOAuthScopesSessionKey(): string
    {
        $userId = Auth::id();

        return 'oauth_scopes_'.($userId ?? 'guest');
    }
}
