<?php

namespace App\Actions\Decorators;

use App\Actions\Concerns\DecorateActions;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Auth;

/**
 * JWT Decorator
 *
 * Handles JWT token authentication and validation for actions.
 * This decorator intercepts handle() calls and validates JWT tokens
 * before allowing action execution.
 *
 * Features:
 * - Automatic JWT token extraction from requests
 * - Token validation before execution
 * - User authentication from tokens
 * - Custom token sources (bearer, header, query, cookie)
 * - Custom validation logic
 * - Proper error handling for missing/invalid tokens
 *
 * How it works:
 * 1. When an action uses AsJWT, JWTDesignPattern recognizes it
 * 2. ActionManager wraps the action with JWTDecorator
 * 3. When handle() is called, the decorator:
 *    - Extracts token from request (bearer, header, query, cookie)
 *    - Validates the token
 *    - Authenticates user from token
 *    - Throws 401 if token is missing or invalid
 *    - Executes the action if token is valid
 *    - Returns the result
 */
class JWTDecorator
{
    use DecorateActions;

    public function __construct($action)
    {
        $this->setAction($action);
    }

    /**
     * Execute the action with JWT authentication.
     *
     * @param  mixed  ...$arguments
     * @return mixed
     *
     * @throws HttpResponseException
     */
    public function handle(...$arguments)
    {
        $token = $this->getTokenFromRequest();

        if (! $token) {
            $this->handleMissingToken();
        }

        if (! $this->validateToken($token)) {
            $this->handleInvalidToken();
        }

        // Set authenticated user from token
        $this->authenticateFromToken($token);

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
     * Get the JWT token from the request.
     */
    protected function getTokenFromRequest(): ?string
    {
        return $this->fromActionMethod('getTokenFromRequest', $this->getDefaultToken());
    }

    /**
     * Get the default token from request.
     */
    protected function getDefaultToken(): ?string
    {
        // Try bearer token first
        $token = request()->bearerToken();

        if ($token) {
            return $token;
        }

        // Try from Authorization header
        $authHeader = request()->header('Authorization');

        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            return substr($authHeader, 7);
        }

        // Try from query parameter
        $token = request()->query('token');

        if ($token) {
            return $token;
        }

        // Try from cookie
        return request()->cookie('jwt_token');
    }

    /**
     * Validate the JWT token.
     */
    protected function validateToken(string $token): bool
    {
        $customValidation = $this->fromActionMethod('validateToken', [$token]);

        if ($customValidation !== null) {
            return (bool) $customValidation;
        }

        // Try to validate using Laravel Sanctum/Passport
        if (method_exists(Auth::guard('api'), 'validateToken')) {
            return Auth::guard('api')->validateToken($token);
        }

        // Basic validation - check if token exists and is not empty
        return ! empty($token);
    }

    /**
     * Authenticate user from token.
     */
    protected function authenticateFromToken(string $token): void
    {
        // Try to authenticate using the token
        if (method_exists(Auth::guard('api'), 'setToken')) {
            Auth::guard('api')->setToken($token);
        }

        // If using Laravel Sanctum/Passport, token is already validated
        // User should be available via Auth::user()

        // Call custom authentication method if exists
        $this->fromActionMethod('authenticateFromToken', [$token]);
    }

    /**
     * Handle missing token error.
     *
     * @throws HttpResponseException
     */
    protected function handleMissingToken(): void
    {
        $customHandler = $this->fromActionMethod('handleMissingToken', []);

        if ($customHandler !== null) {
            return;
        }

        if (request()->expectsJson()) {
            abort(401, 'Authentication token required.');
        }

        abort(401, 'Authentication token required.');
    }

    /**
     * Handle invalid token error.
     *
     * @throws HttpResponseException
     */
    protected function handleInvalidToken(): void
    {
        $customHandler = $this->fromActionMethod('handleInvalidToken', []);

        if ($customHandler !== null) {
            return;
        }

        if (request()->expectsJson()) {
            abort(401, 'Invalid or expired authentication token.');
        }

        abort(401, 'Invalid or expired authentication token.');
    }
}
