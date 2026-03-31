<?php

namespace App\Actions\Concerns;

/**
 * Handles JWT token authentication and validation.
 *
 * This trait is a marker that enables automatic JWT authentication via JWTDecorator.
 * When an action uses AsJWT, JWTDesignPattern recognizes it and
 * ActionManager wraps the action with JWTDecorator.
 *
 * How it works:
 * 1. Action uses AsJWT trait (marker)
 * 2. JWTDesignPattern recognizes the trait
 * 3. ActionManager wraps action with JWTDecorator
 * 4. When handle() is called, the decorator:
 *    - Extracts JWT token from request (bearer, header, query, cookie)
 *    - Validates the token
 *    - Authenticates user from token
 *    - Throws 401 if token is missing or invalid
 *    - Executes the action if token is valid
 *    - Returns the result
 *
 * Benefits:
 * - Automatic JWT token validation
 * - Multiple token source support (bearer, header, query, cookie)
 * - Custom validation logic
 * - User authentication from tokens
 * - Works with Laravel Sanctum/Passport
 * - Works with ActionManager's decorator system
 * - Can be composed with other decorators
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * JWTDecorator, which automatically wraps actions and adds JWT authentication.
 * This follows the same pattern as AsPermission, AsLogger, AsLock, and other
 * decorator-based concerns.
 *
 * Configuration:
 * - Implement `getTokenFromRequest()` method to customize token extraction
 * - Implement `validateToken(string $token)` method to customize validation
 * - Implement `authenticateFromToken(string $token)` method for custom authentication
 * - Implement `handleMissingToken()` method for custom missing token handling
 * - Implement `handleInvalidToken()` method for custom invalid token handling
 *
 * Token Sources (checked in order):
 * 1. Bearer token (Authorization: Bearer <token>)
 * 2. Authorization header (Authorization: Bearer <token>)
 * 3. Query parameter (?token=...)
 * 4. Cookie (jwt_token)
 *
 * @example
 * // ============================================
 * // Example 1: Basic JWT Authentication
 * // ============================================
 * class ProtectedApiAction extends Actions
 * {
 *     use AsJWT;
 *
 *     public function handle(): array
 *     {
 *         // This code only runs if JWT is valid
 *         return ['data' => 'protected', 'user' => Auth::user()];
 *     }
 * }
 *
 * // Usage:
 * // Request must include: Authorization: Bearer <token>
 * ProtectedApiAction::run();
 * @example
 * // ============================================
 * // Example 2: Custom Token Source
 * // ============================================
 * class CustomTokenAction extends Actions
 * {
 *     use AsJWT;
 *
 *     public function handle(): array
 *     {
 *         return ['data' => 'protected'];
 *     }
 *
 *     protected function getTokenFromRequest(): ?string
 *     {
 *         // Get token from custom header
 *         return request()->header('X-API-Token');
 *     }
 * }
 *
 * // Usage:
 * // Request must include: X-API-Token: <token>
 * CustomTokenAction::run();
 * @example
 * // ============================================
 * // Example 3: Custom Token Validation
 * // ============================================
 * class StrictValidationAction extends Actions
 * {
 *     use AsJWT;
 *
 *     public function handle(): array
 *     {
 *         return ['data' => 'protected'];
 *     }
 *
 *     protected function validateToken(string $token): bool
 *     {
 *         // Custom validation using JWT library
 *         try {
 *             $decoded = \Firebase\JWT\JWT::decode($token, $this->getPublicKey(), ['RS256']);
 *             return $decoded->exp > time();
 *         } catch (\Exception $e) {
 *             return false;
 *         }
 *     }
 *
 *     protected function getPublicKey(): string
 *     {
 *         return file_get_contents(storage_path('keys/public.pem'));
 *     }
 * }
 * @example
 * // ============================================
 * // Example 4: Query Parameter Token
 * // ============================================
 * class QueryTokenAction extends Actions
 * {
 *     use AsJWT;
 *
 *     public function handle(): array
 *     {
 *         return ['data' => 'protected'];
 *     }
 *
 *     protected function getTokenFromRequest(): ?string
 *     {
 *         // Get token from query parameter
 *         return request()->query('api_token');
 *     }
 * }
 *
 * // Usage:
 * // GET /api/endpoint?api_token=<token>
 * QueryTokenAction::run();
 * @example
 * // ============================================
 * // Example 5: Cookie-Based Token
 * // ============================================
 * class CookieTokenAction extends Actions
 * {
 *     use AsJWT;
 *
 *     public function handle(): array
 *     {
 *         return ['data' => 'protected'];
 *     }
 *
 *     protected function getTokenFromRequest(): ?string
 *     {
 *         // Get token from cookie
 *         return request()->cookie('auth_token');
 *     }
 * }
 *
 * // Usage:
 * // Cookie: auth_token=<token>
 * CookieTokenAction::run();
 * @example
 * // ============================================
 * // Example 6: Laravel Sanctum Integration
 * // ============================================
 * class SanctumAction extends Actions
 * {
 *     use AsJWT;
 *
 *     public function handle(): array
 *     {
 *         // User is authenticated via Sanctum token
 *         return ['user' => Auth::user()];
 *     }
 *
 *     protected function validateToken(string $token): bool
 *     {
 *         // Sanctum handles validation automatically
 *         return Auth::guard('sanctum')->check();
 *     }
 *
 *     protected function authenticateFromToken(string $token): void
 *     {
 *         // Sanctum authenticates automatically from bearer token
 *         Auth::guard('sanctum')->setRequest(request());
 *     }
 * }
 * @example
 * // ============================================
 * // Example 7: Laravel Passport Integration
 * // ============================================
 * class PassportAction extends Actions
 * {
 *     use AsJWT;
 *
 *     public function handle(): array
 *     {
 *         return ['user' => Auth::user()];
 *     }
 *
 *     protected function validateToken(string $token): bool
 *     {
 *         // Passport validates OAuth tokens
 *         try {
 *             $token = \Laravel\Passport\Token::where('id', $token)->first();
 *             return $token && ! $token->revoked;
 *         } catch (\Exception $e) {
 *             return false;
 *         }
 *     }
 * }
 * @example
 * // ============================================
 * // Example 8: Custom Error Handling
 * // ============================================
 * class CustomErrorAction extends Actions
 * {
 *     use AsJWT;
 *
 *     public function handle(): array
 *     {
 *         return ['data' => 'protected'];
 *     }
 *
 *     protected function handleMissingToken(): void
 *     {
 *         // Custom handling for missing token
 *         if (request()->expectsJson()) {
 *             response()->json([
 *                 'error' => 'Authentication required',
 *                 'code' => 'AUTH_REQUIRED',
 *             ], 401)->send();
 *             exit;
 *         }
 *
 *         redirect()->route('login')->send();
 *         exit;
 *     }
 *
 *     protected function handleInvalidToken(): void
 *     {
 *         // Custom handling for invalid token
 *         if (request()->expectsJson()) {
 *             response()->json([
 *                 'error' => 'Invalid or expired token',
 *                 'code' => 'AUTH_INVALID',
 *             ], 401)->send();
 *             exit;
 *         }
 *
 *         redirect()->route('login')->with('error', 'Session expired')->send();
 *         exit;
 *     }
 * }
 * @example
 * // ============================================
 * // Example 9: Token Refresh Support
 * // ============================================
 * class RefreshTokenAction extends Actions
 * {
 *     use AsJWT;
 *
 *     public function handle(): array
 *     {
 *         return ['data' => 'protected'];
 *     }
 *
 *     protected function validateToken(string $token): bool
 *     {
 *         if ($this->isTokenExpired($token)) {
 *             // Try to refresh token
 *             $newToken = $this->refreshToken($token);
 *             if ($newToken) {
 *                 // Set new token in response
 *                 response()->header('X-New-Token', $newToken);
 *                 return true;
 *             }
 *             return false;
 *         }
 *
 *         return $this->validateTokenSignature($token);
 *     }
 *
 *     protected function isTokenExpired(string $token): bool
 *     {
 *         // Check token expiration
 *         return false;
 *     }
 *
 *     protected function refreshToken(string $token): ?string
 *     {
 *         // Refresh token logic
 *         return null;
 *     }
 *
 *     protected function validateTokenSignature(string $token): bool
 *     {
 *         // Validate token signature
 *         return true;
 *     }
 * }
 * @example
 * // ============================================
 * // Example 10: Combining with Other Decorators
 * // ============================================
 * class SecureApiAction extends Actions
 * {
 *     use AsJWT;
 *     use AsPermission;
 *     use AsLogger;
 *     use AsThrottle;
 *
 *     public function handle(): array
 *     {
 *         return ['data' => 'protected'];
 *     }
 *
 *     protected function getRequiredPermissions(): array
 *     {
 *         return ['api:read'];
 *     }
 * }
 *
 * // All decorators work together:
 * // - JWTDecorator validates token
 * // - PermissionDecorator checks permissions
 * // - LoggerDecorator tracks execution
 * // - ThrottleDecorator rate limits requests
 * @example
 * // ============================================
 * // Example 11: Multi-Guard Support
 * // ============================================
 * class MultiGuardAction extends Actions
 * {
 *     use AsJWT;
 *
 *     public function handle(): array
 *     {
 *         return ['user' => Auth::user()];
 *     }
 *
 *     protected function authenticateFromToken(string $token): void
 *     {
 *         // Try multiple guards
 *         $guards = ['api', 'sanctum', 'passport'];
 *
 *         foreach ($guards as $guard) {
 *             if (Auth::guard($guard)->check()) {
 *                 return;
 *             }
 *         }
 *     }
 * }
 * @example
 * // ============================================
 * // Example 12: Token Blacklist Check
 * // ============================================
 * class BlacklistCheckAction extends Actions
 * {
 *     use AsJWT;
 *
 *     public function handle(): array
 *     {
 *         return ['data' => 'protected'];
 *     }
 *
 *     protected function validateToken(string $token): bool
 *     {
 *         // Check if token is blacklisted
 *         if (Cache::has("blacklisted_token:".md5($token))) {
 *             return false;
 *         }
 *
 *         // Validate token signature and expiration
 *         return $this->validateTokenSignature($token);
 *     }
 *
 *     protected function validateTokenSignature(string $token): bool
 *     {
 *         // Token validation logic
 *         return true;
 *     }
 * }
 * @example
 * // ============================================
 * // Example 13: Role-Based Token Validation
 * // ============================================
 * class RoleBasedAction extends Actions
 * {
 *     use AsJWT;
 *
 *     public function handle(): array
 *     {
 *         return ['data' => 'protected'];
 *     }
 *
 *     protected function validateToken(string $token): bool
 *     {
 *         // Validate token
 *         if (! $this->validateTokenSignature($token)) {
 *             return false;
 *         }
 *
 *         // Extract user from token
 *         $user = $this->getUserFromToken($token);
 *
 *         // Check if user has required role
 *         return $user && $user->hasRole('api-user');
 *     }
 *
 *     protected function getUserFromToken(string $token)
 *     {
 *         // Extract user from token
 *         return null;
 *     }
 *
 *     protected function validateTokenSignature(string $token): bool
 *     {
 *         // Token validation logic
 *         return true;
 *     }
 * }
 * @example
 * // ============================================
 * // Example 14: API Key + JWT Hybrid
 * // ============================================
 * class HybridAuthAction extends Actions
 * {
 *     use AsJWT;
 *
 *     public function handle(): array
 *     {
 *         return ['data' => 'protected'];
 *     }
 *
 *     protected function getTokenFromRequest(): ?string
 *     {
 *         // Try JWT token first
 *         $token = request()->bearerToken();
 *
 *         if ($token) {
 *             return $token;
 *         }
 *
 *         // Fallback to API key
 *         $apiKey = request()->header('X-API-Key');
 *         if ($apiKey && $this->validateApiKey($apiKey)) {
 *             // Generate temporary token from API key
 *             return $this->generateTokenFromApiKey($apiKey);
 *         }
 *
 *         return null;
 *     }
 *
 *     protected function validateApiKey(string $apiKey): bool
 *     {
 *         return \App\Models\ApiKey::where('key', $apiKey)->exists();
 *     }
 *
 *     protected function generateTokenFromApiKey(string $apiKey): string
 *     {
 *         // Generate temporary JWT from API key
 *         return 'temp_token';
 *     }
 * }
 * @example
 * // ============================================
 * // Example 15: Stateless JWT Validation
 * // ============================================
 * class StatelessAction extends Actions
 * {
 *     use AsJWT;
 *
 *     public function handle(): array
 *     {
 *         return ['data' => 'protected'];
 *     }
 *
 *     protected function validateToken(string $token): bool
 *     {
 *         // Stateless validation - no database lookup needed
 *         try {
 *             $decoded = \Firebase\JWT\JWT::decode(
 *                 $token,
 *                 $this->getPublicKey(),
 *                 ['RS256']
 *             );
 *
 *             // Check expiration
 *             if (isset($decoded->exp) && $decoded->exp < time()) {
 *                 return false;
 *             }
 *
 *             // Set user from token payload
 *             if (isset($decoded->user_id)) {
 *                 Auth::loginUsingId($decoded->user_id);
 *             }
 *
 *             return true;
 *         } catch (\Exception $e) {
 *             return false;
 *         }
 *     }
 *
 *     protected function getPublicKey(): string
 *     {
 *         return file_get_contents(storage_path('keys/public.pem'));
 *     }
 * }
 * @example
 * // ============================================
 * // Example 16: Token with Custom Claims
 * // ============================================
 * class CustomClaimsAction extends Actions
 * {
 *     use AsJWT;
 *
 *     public function handle(): array
 *     {
 *         return ['data' => 'protected'];
 *     }
 *
 *     protected function validateToken(string $token): bool
 *     {
 *         try {
 *             $decoded = \Firebase\JWT\JWT::decode($token, $this->getPublicKey(), ['RS256']);
 *
 *             // Validate custom claims
 *             if (! isset($decoded->permissions) || ! in_array('api:access', $decoded->permissions)) {
 *                 return false;
 *             }
 *
 *             // Validate tenant claim
 *             if (isset($decoded->tenant_id) && $decoded->tenant_id !== request()->header('X-Tenant-ID')) {
 *                 return false;
 *             }
 *
 *             return true;
 *         } catch (\Exception $e) {
 *             return false;
 *         }
 *     }
 *
 *     protected function getPublicKey(): string
 *     {
 *         return file_get_contents(storage_path('keys/public.pem'));
 *     }
 * }
 * @example
 * // ============================================
 * // Example 17: Token Rotation
 * // ============================================
 * class TokenRotationAction extends Actions
 * {
 *     use AsJWT;
 *
 *     public function handle(): array
 *     {
 *         return ['data' => 'protected'];
 *     }
 *
 *     protected function validateToken(string $token): bool
 *     {
 *         // Check if token needs rotation
 *         if ($this->shouldRotateToken($token)) {
 *             $newToken = $this->rotateToken($token);
 *             if ($newToken) {
 *                 response()->header('X-New-Token', $newToken);
 *                 return true;
 *             }
 *         }
 *
 *         return $this->validateTokenSignature($token);
 *     }
 *
 *     protected function shouldRotateToken(string $token): bool
 *     {
 *         // Check if token is near expiration
 *         return false;
 *     }
 *
 *     protected function rotateToken(string $token): ?string
 *     {
 *         // Generate new token
 *         return null;
 *     }
 *
 *     protected function validateTokenSignature(string $token): bool
 *     {
 *         return true;
 *     }
 * }
 * @example
 * // ============================================
 * // Example 18: Microservice JWT Validation
 * // ============================================
 * class MicroserviceAction extends Actions
 * {
 *     use AsJWT;
 *
 *     public function handle(): array
 *     {
 *         return ['data' => 'protected'];
 *     }
 *
 *     protected function validateToken(string $token): bool
 *     {
 *         // Validate token from another microservice
 *         $issuer = $this->getTokenIssuer($token);
 *
 *         if (! $issuer) {
 *             return false;
 *         }
 *
 *         // Get public key from issuer's JWKS endpoint
 *         $publicKey = $this->getPublicKeyFromIssuer($issuer);
 *
 *         if (! $publicKey) {
 *             return false;
 *         }
 *
 *         // Validate token with issuer's public key
 *         return $this->validateTokenWithKey($token, $publicKey);
 *     }
 *
 *     protected function getTokenIssuer(string $token): ?string
 *     {
 *         // Extract issuer from token
 *         return null;
 *     }
 *
 *     protected function getPublicKeyFromIssuer(string $issuer): ?string
 *     {
 *         // Fetch public key from issuer's JWKS endpoint
 *         return null;
 *     }
 *
 *     protected function validateTokenWithKey(string $token, string $publicKey): bool
 *     {
 *         // Validate token with public key
 *         return true;
 *     }
 * }
 * @example
 * // ============================================
 * // Example 19: JWT with Rate Limiting
 * // ============================================
 * class RateLimitedJwtAction extends Actions
 * {
 *     use AsJWT;
 *     use AsThrottle;
 *
 *     public function handle(): array
 *     {
 *         return ['data' => 'protected'];
 *     }
 *
 *     protected function getThrottleKey(): string
 *     {
 *         // Rate limit per user (from JWT)
 *         return 'api:'.Auth::id();
 *     }
 * }
 *
 * // JWT authentication happens first, then rate limiting
 * @example
 * // ============================================
 * // Example 20: JWT Token Logging
 * // ============================================
 * class LoggedJwtAction extends Actions
 * {
 *     use AsJWT;
 *     use AsLogger;
 *
 *     public function handle(): array
 *     {
 *         return ['data' => 'protected'];
 *     }
 *
 *     protected function getLogChannel(): string
 *     {
 *         return 'api';
 *     }
 *
 *     protected function getSensitiveParameters(): array
 *     {
 *         // Don't log tokens
 *         return ['token', 'jwt', 'authorization'];
 *     }
 * }
 *
 * // JWT validation happens, then execution is logged (without token)
 * @example
 * // ============================================
 * // Example 21: Conditional JWT Requirement
 * // ============================================
 * class ConditionalJwtAction extends Actions
 * {
 *     use AsJWT;
 *
 *     public function handle(): array
 *     {
 *         return ['data' => 'protected'];
 *     }
 *
 *     protected function getTokenFromRequest(): ?string
 *     {
 *         // Only require JWT in production
 *         if (app()->environment('local')) {
 *             return 'dev-token'; // Bypass in development
 *         }
 *
 *         return request()->bearerToken();
 *     }
 *
 *     protected function validateToken(string $token): bool
 *     {
 *         // Skip validation in development
 *         if (app()->environment('local')) {
 *             return true;
 *         }
 *
 *         return $this->validateTokenSignature($token);
 *     }
 *
 *     protected function validateTokenSignature(string $token): bool
 *     {
 *         return true;
 *     }
 * }
 * @example
 * // ============================================
 * // Example 22: JWT with IP Whitelist
 * // ============================================
 * class WhitelistedJwtAction extends Actions
 * {
 *     use AsJWT;
 *
 *     public function handle(): array
 *     {
 *         return ['data' => 'protected'];
 *     }
 *
 *     protected function validateToken(string $token): bool
 *     {
 *         // Validate token
 *         if (! $this->validateTokenSignature($token)) {
 *             return false;
 *         }
 *
 *         // Check IP whitelist
 *         $user = $this->getUserFromToken($token);
 *         $allowedIps = $user->allowed_ips ?? [];
 *
 *         if (! empty($allowedIps) && ! in_array(request()->ip(), $allowedIps)) {
 *             return false;
 *         }
 *
 *         return true;
 *     }
 *
 *     protected function getUserFromToken(string $token)
 *     {
 *         return null;
 *     }
 *
 *     protected function validateTokenSignature(string $token): bool
 *     {
 *         return true;
 *     }
 * }
 * @example
 * // ============================================
 * // Example 23: JWT Token Refresh Endpoint
 * // ============================================
 * class RefreshTokenAction extends Actions
 * {
 *     use AsJWT;
 *
 *     public function handle(): array
 *     {
 *         $token = $this->getTokenFromRequest();
 *         $refreshToken = request()->input('refresh_token');
 *
 *         // Validate refresh token
 *         if (! $this->validateRefreshToken($refreshToken)) {
 *             abort(401, 'Invalid refresh token');
 *         }
 *
 *         // Generate new access token
 *         $newToken = $this->generateNewToken($token);
 *
 *         return [
 *             'access_token' => $newToken,
 *             'expires_in' => 3600,
 *         ];
 *     }
 *
 *     protected function validateRefreshToken(string $refreshToken): bool
 *     {
 *         // Validate refresh token logic
 *         return true;
 *     }
 *
 *     protected function generateNewToken(string $oldToken): string
 *     {
 *         // Generate new token
 *         return 'new_token';
 *     }
 * }
 * @example
 * // ============================================
 * // Example 24: JWT with Device Fingerprinting
 * // ============================================
 * class DeviceFingerprintAction extends Actions
 * {
 *     use AsJWT;
 *
 *     public function handle(): array
 *     {
 *         return ['data' => 'protected'];
 *     }
 *
 *     protected function validateToken(string $token): bool
 *     {
 *         // Validate token signature
 *         if (! $this->validateTokenSignature($token)) {
 *             return false;
 *         }
 *
 *         // Check device fingerprint
 *         $deviceFingerprint = request()->header('X-Device-Fingerprint');
 *         $tokenFingerprint = $this->getDeviceFingerprintFromToken($token);
 *
 *         if ($deviceFingerprint && $tokenFingerprint && $deviceFingerprint !== $tokenFingerprint) {
 *             return false; // Device mismatch
 *         }
 *
 *         return true;
 *     }
 *
 *     protected function getDeviceFingerprintFromToken(string $token): ?string
 *     {
 *         // Extract device fingerprint from token
 *         return null;
 *     }
 *
 *     protected function validateTokenSignature(string $token): bool
 *     {
 *         return true;
 *     }
 * }
 * @example
 * // ============================================
 * // Example 25: JWT Token Revocation Check
 * // ============================================
 * class RevocationCheckAction extends Actions
 * {
 *     use AsJWT;
 *
 *     public function handle(): array
 *     {
 *         return ['data' => 'protected'];
 *     }
 *
 *     protected function validateToken(string $token): bool
 *     {
 *         // Validate token signature first
 *         if (! $this->validateTokenSignature($token)) {
 *             return false;
 *         }
 *
 *         // Check if token is revoked
 *         $tokenId = $this->getTokenId($token);
 *
 *         if ($tokenId && Cache::has("revoked_token:{$tokenId}")) {
 *             return false; // Token has been revoked
 *         }
 *
 *         return true;
 *     }
 *
 *     protected function getTokenId(string $token): ?string
 *     {
 *         // Extract token ID from token
 *         return null;
 *     }
 *
 *     protected function validateTokenSignature(string $token): bool
 *     {
 *         return true;
 *     }
 * }
 */
trait AsJWT
{
    // This is a marker trait - the actual JWT authentication is handled by JWTDecorator
    // via the JWTDesignPattern and ActionManager
}
