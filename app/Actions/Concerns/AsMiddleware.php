<?php

namespace App\Actions\Concerns;

use App\Actions\Decorators\MiddlewareDecorator;
use App\Actions\DesignPatterns\MiddlewareDesignPattern;
use Illuminate\Http\Middleware;

/**
 * Allows actions to be used as HTTP middleware in Laravel routes.
 *
 * Provides middleware capabilities for actions, allowing them to be registered
 * as HTTP middleware in Laravel's routing system. Actions using this trait
 * can intercept HTTP requests and perform operations before passing them to
 * the next middleware or route handler.
 *
 * How it works:
 * - MiddlewareDesignPattern recognizes actions using AsMiddleware
 * - ActionManager wraps the action with MiddlewareDecorator
 * - When used in routes, Laravel calls the decorator's handle() method
 * - The decorator calls the action's asMiddleware() or handle() method
 * - The action can modify the request or abort early
 * - The action must return a response or call $next($request)
 *
 * Benefits:
 * - Use actions as HTTP middleware
 * - Reusable middleware logic
 * - Easy to test
 * - Works with Laravel's middleware system
 * - Supports both asMiddleware() and handle() methods
 * - Can be registered globally, in groups, or per route
 *
 * Note: This IS a decorator pattern. The trait is a marker that triggers
 * MiddlewareDecorator, which automatically wraps actions and makes them
 * compatible with Laravel's middleware interface. This follows the same
 * pattern as AsOAuth, AsPermission, and other decorator-based concerns.
 *
 * Middleware Methods:
 * - `asMiddleware($request, $next)`: Primary method for middleware logic
 * - `handle($request, $next)`: Alternative method name (fallback)
 *
 * @example
 * // ============================================
 * // Example 1: Basic Middleware
 * // ============================================
 * class CheckSubscription extends Actions
 * {
 *     use AsMiddleware;
 *
 *     public function asMiddleware($request, $next)
 *     {
 *         if (! auth()->user()->hasActiveSubscription()) {
 *             abort(403, 'Subscription required');
 *         }
 *
 *         return $next($request);
 *     }
 * }
 *
 * // In routes/web.php:
 * Route::middleware(CheckSubscription::class)->group(function () {
 *     Route::get('/premium', function () {
 *         return view('premium');
 *     });
 * });
 * @example
 * // ============================================
 * // Example 2: Authentication Middleware
 * // ============================================
 * class RequireAuth extends Actions
 * {
 *     use AsMiddleware;
 *
 *     public function asMiddleware($request, $next)
 *     {
 *         if (! auth()->check()) {
 *             return redirect()->route('login');
 *         }
 *
 *         return $next($request);
 *     }
 * }
 *
 * // Usage:
 * Route::middleware(RequireAuth::class)->group(function () {
 *     Route::get('/dashboard', [DashboardController::class, 'index']);
 * });
 * @example
 * // ============================================
 * // Example 3: Role-Based Middleware
 * // ============================================
 * class RequireRole extends Actions
 * {
 *     use AsMiddleware;
 *
 *     public function __construct(
 *         public string $role
 *     ) {}
 *
 *     public function asMiddleware($request, $next)
 *     {
 *         if (! auth()->user()->hasRole($this->role)) {
 *             abort(403, 'Insufficient permissions');
 *         }
 *
 *         return $next($request);
 *     }
 * }
 *
 * // Usage:
 * Route::middleware([RequireRole::class.':admin'])->group(function () {
 *     Route::get('/admin', [AdminController::class, 'index']);
 * });
 * @example
 * // ============================================
 * // Example 4: Rate Limiting Middleware
 * // ============================================
 * class RateLimitRequests extends Actions
 * {
 *     use AsMiddleware;
 *
 *     public function asMiddleware($request, $next)
 *     {
 *         $key = 'rate_limit:'.auth()->id();
 *
 *         if (RateLimiter::tooManyAttempts($key, 60)) {
 *             return response()->json([
 *                 'message' => 'Too many requests',
 *             ], 429);
 *         }
 *
 *         RateLimiter::hit($key, 60);
 *
 *         return $next($request);
 *     }
 * }
 *
 * // Usage:
 * Route::middleware(RateLimitRequests::class)->group(function () {
 *     Route::post('/api/endpoint', [ApiController::class, 'store']);
 * });
 * @example
 * // ============================================
 * // Example 5: API Key Validation
 * // ============================================
 * class ValidateApiKey extends Actions
 * {
 *     use AsMiddleware;
 *
 *     public function asMiddleware($request, $next)
 *     {
 *         $apiKey = $request->header('X-API-Key');
 *
 *         if (! $apiKey || ! $this->isValidApiKey($apiKey)) {
 *             return response()->json([
 *                 'error' => 'Invalid API key',
 *             ], 401);
 *         }
 *
 *         // Attach API key info to request
 *         $request->merge(['api_key' => $this->getApiKeyInfo($apiKey)]);
 *
 *         return $next($request);
 *     }
 *
 *     protected function isValidApiKey(string $key): bool
 *     {
 *         return ApiKey::where('key', $key)->where('active', true)->exists();
 *     }
 *
 *     protected function getApiKeyInfo(string $key): array
 *     {
 *         $apiKey = ApiKey::where('key', $key)->first();
 *
 *         return [
 *             'id' => $apiKey->id,
 *             'user_id' => $apiKey->user_id,
 *         ];
 *     }
 * }
 *
 * // Usage:
 * Route::middleware(ValidateApiKey::class)->prefix('api')->group(function () {
 *     Route::get('/data', [ApiController::class, 'index']);
 * });
 * @example
 * // ============================================
 * // Example 6: Request Logging Middleware
 * // ============================================
 * class LogRequests extends Actions
 * {
 *     use AsMiddleware;
 *
 *     public function asMiddleware($request, $next)
 *     {
 *         $startTime = microtime(true);
 *
 *         $response = $next($request);
 *
 *         $duration = microtime(true) - $startTime;
 *
 *         \Log::info('Request processed', [
 *             'method' => $request->method(),
 *             'url' => $request->fullUrl(),
 *             'status' => $response->getStatusCode(),
 *             'duration' => $duration,
 *         ]);
 *
 *         return $response;
 *     }
 * }
 *
 * // Usage:
 * Route::middleware(LogRequests::class)->group(function () {
 *     // All routes in this group are logged
 * });
 * @example
 * // ============================================
 * // Example 7: CORS Middleware
 * // ============================================
 * class HandleCors extends Actions
 * {
 *     use AsMiddleware;
 *
 *     public function asMiddleware($request, $next)
 *     {
 *         $response = $next($request);
 *
 *         return $response->header('Access-Control-Allow-Origin', '*')
 *             ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
 *             ->header('Access-Control-Allow-Headers', 'Content-Type, Authorization');
 *     }
 * }
 *
 * // Usage:
 * Route::middleware(HandleCors::class)->prefix('api')->group(function () {
 *     Route::get('/data', [ApiController::class, 'index']);
 * });
 * @example
 * // ============================================
 * // Example 8: Maintenance Mode Check
 * // ============================================
 * class CheckMaintenanceMode extends Actions
 * {
 *     use AsMiddleware;
 *
 *     public function asMiddleware($request, $next)
 *     {
 *         if (app()->isDownForMaintenance() && ! $this->isAllowed($request)) {
 *             return response()->view('maintenance', [], 503);
 *         }
 *
 *         return $next($request);
 *     }
 *
 *     protected function isAllowed($request): bool
 *     {
 *         // Allow admins even during maintenance
 *         return auth()->check() && auth()->user()->isAdmin();
 *     }
 * }
 *
 * // Usage:
 * Route::middleware(CheckMaintenanceMode::class)->group(function () {
 *     // All routes check maintenance mode
 * });
 * @example
 * // ============================================
 * // Example 9: Tenant/Organization Scoping
 * // ============================================
 * class SetTenantContext extends Actions
 * {
 *     use AsMiddleware;
 *
 *     public function asMiddleware($request, $next)
 *     {
 *         $tenantId = $request->header('X-Tenant-ID') ?? $request->route('tenant');
 *
 *         if ($tenantId) {
 *             $tenant = Tenant::findOrFail($tenantId);
 *             app()->instance('tenant', $tenant);
 *             \DB::setDefaultConnection($tenant->database_connection);
 *         }
 *
 *         return $next($request);
 *     }
 * }
 *
 * // Usage:
 * Route::middleware(SetTenantContext::class)->prefix('{tenant}')->group(function () {
 *     Route::get('/dashboard', [DashboardController::class, 'index']);
 * });
 * @example
 * // ============================================
 * // Example 10: Request Transformation
 * // ============================================
 * class TransformRequest extends Actions
 * {
 *     use AsMiddleware;
 *
 *     public function asMiddleware($request, $next)
 *     {
 *         // Transform request data
 *         if ($request->has('email')) {
 *             $request->merge(['email' => strtolower($request->input('email'))]);
 *         }
 *
 *         // Add default values
 *         if (! $request->has('locale')) {
 *             $request->merge(['locale' => app()->getLocale()]);
 *         }
 *
 *         return $next($request);
 *     }
 * }
 *
 * // Usage:
 * Route::middleware(TransformRequest::class)->group(function () {
 *     Route::post('/users', [UserController::class, 'store']);
 * });
 * @example
 * // ============================================
 * // Example 11: Using handle() Method Instead
 * // ============================================
 * class SimpleMiddleware extends Actions
 * {
 *     use AsMiddleware;
 *
 *     // Can use handle() instead of asMiddleware()
 *     public function handle($request, $next)
 *     {
 *         // Middleware logic
 *         return $next($request);
 *     }
 * }
 *
 * // Usage:
 * Route::middleware(SimpleMiddleware::class)->group(function () {
 *     // Routes
 * });
 * @example
 * // ============================================
 * // Example 12: Combining with Other Concerns
 * // ============================================
 * class ComprehensiveMiddleware extends Actions
 * {
 *     use AsMiddleware;
 *     use AsObservable;
 *     use AsValidated;
 *
 *     public function asMiddleware($request, $next)
 *     {
 *         // Middleware logic with observability
 *         return $next($request);
 *     }
 * }
 *
 * // Usage:
 * Route::middleware(ComprehensiveMiddleware::class)->group(function () {
 *     // Routes with observability
 * });
 * @example
 * // ============================================
 * // Example 13: Conditional Middleware Logic
 * // ============================================
 * class ConditionalMiddleware extends Actions
 * {
 *     use AsMiddleware;
 *
 *     public function asMiddleware($request, $next)
 *     {
 *         // Only apply logic for specific routes
 *         if ($request->is('api/*')) {
 *             // API-specific logic
 *             $request->merge(['api_version' => 'v1']);
 *         }
 *
 *         return $next($request);
 *     }
 * }
 *
 * // Usage:
 * Route::middleware(ConditionalMiddleware::class)->group(function () {
 *     Route::prefix('api')->group(function () {
 *         Route::get('/data', [ApiController::class, 'index']);
 *     });
 * });
 * @example
 * // ============================================
 * // Example 14: Response Modification
 * // ============================================
 * class AddResponseHeaders extends Actions
 * {
 *     use AsMiddleware;
 *
 *     public function asMiddleware($request, $next)
 *     {
 *         $response = $next($request);
 *
 *         return $response->header('X-API-Version', '1.0')
 *             ->header('X-Request-ID', uniqid());
 *     }
 * }
 *
 * // Usage:
 * Route::middleware(AddResponseHeaders::class)->group(function () {
 *     Route::get('/api/data', [ApiController::class, 'index']);
 * });
 * @example
 * // ============================================
 * // Example 15: Real-World Usage - Multi-Tenant API
 * // ============================================
 * class MultiTenantApiMiddleware extends Actions
 * {
 *     use AsMiddleware;
 *     use AsObservable;
 *
 *     public function asMiddleware($request, $next)
 *     {
 *         // Validate API key
 *         $apiKey = $request->header('X-API-Key');
 *         if (! $apiKey) {
 *             return response()->json(['error' => 'API key required'], 401);
 *         }
 *
 *         $token = PersonalAccessToken::findToken($apiKey);
 *         if (! $token || ! $token->tokenable) {
 *             return response()->json(['error' => 'Invalid API key'], 401);
 *         }
 *
 *         // Set tenant context
 *         $tenant = $token->tokenable->tenant;
 *         app()->instance('tenant', $tenant);
 *         \DB::setDefaultConnection($tenant->database_connection);
 *
 *         // Rate limiting
 *         $key = "api_rate_limit:{$token->id}";
 *         if (RateLimiter::tooManyAttempts($key, 100)) {
 *             return response()->json(['error' => 'Rate limit exceeded'], 429);
 *         }
 *         RateLimiter::hit($key, 60);
 *
 *         // Attach user to request
 *         $request->setUserResolver(fn () => $token->tokenable);
 *
 *         // Log request
 *         \Log::info('API request', [
 *             'tenant' => $tenant->id,
 *             'user' => $token->tokenable->id,
 *             'endpoint' => $request->path(),
 *         ]);
 *
 *         return $next($request);
 *     }
 * }
 *
 * // In routes/api.php:
 * Route::middleware(MultiTenantApiMiddleware::class)->prefix('api/v1')->group(function () {
 *     Route::get('/users', [ApiUserController::class, 'index']);
 *     Route::get('/projects', [ApiProjectController::class, 'index']);
 * });
 *
 * @see MiddlewareDecorator
 * @see MiddlewareDesignPattern
 * @see Middleware
 */
trait AsMiddleware
{
    // This is a marker trait - it doesn't add any functionality.
    // It serves as a semantic indicator that the action can be used as HTTP middleware.
    // The actual middleware logic should be implemented in the action's asMiddleware() or handle() method.
    // The MiddlewareDecorator automatically wraps actions using this trait to make them
    // compatible with Laravel's middleware interface.
}
