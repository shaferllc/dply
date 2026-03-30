<?php

namespace App\Actions\Concerns;

/**
 * Enables an action to be used as a Laravel controller.
 *
 * This trait works with the design pattern system:
 * - The trait marks the action as controller-capable
 * - ControllerDesignPattern recognizes when the action is used in routes
 * - ControllerDecorator is automatically applied when Laravel resolves the controller
 *
 * @method array getControllerMiddleware() Get middleware to apply to this controller action
 * @method \Illuminate\Http\Resources\Json\JsonResource jsonResponse(mixed $response, \Illuminate\Http\Request $request) Transform response for JSON requests
 * @method \Illuminate\Http\Response htmlResponse(mixed $response, \Illuminate\Http\Request $request) Transform response for HTML requests
 * @method void routes(\Illuminate\Routing\Router $router) Register routes for this action
 * @method \Illuminate\Http\Response asController(\App\Actions\ActionRequest $request) Handle the controller request (alternative to handle())
 *
 * @example Basic usage with route registration
 * ```php
 * class CreatePost extends Actions
 * {
 *     use AsController;
 *
 *     public static function routes(Router $router): void
 *     {
 *         $router->post('posts', static::class)
 *             ->name('posts.create')
 *             ->middleware('auth');
 *     }
 *
 *     public function handle(array $data): Post
 *     {
 *         return Post::create($data);
 *     }
 * }
 * ```
 * @example Using asController() for custom HTTP responses
 * ```php
 * class CreatePost extends Actions
 * {
 *     use AsController;
 *     use ApiResponseHelpers;
 *
 *     public static function routes(Router $router): void
 *     {
 *         $router->post('posts', static::class)->name('posts.create');
 *     }
 *
 *     public function asController(ActionRequest $request): JsonResponse
 *     {
 *         $post = $this->handle($request->validated());
 *
 *         return $this->respondCreated([
 *             'message' => 'Post created successfully',
 *             'post' => $post,
 *         ]);
 *     }
 *
 *     public function handle(array $data): Post
 *     {
 *         return Post::create($data);
 *     }
 * }
 * ```
 * @example Using route parameters with authorization
 * ```php
 * class UpdatePost extends Actions
 * {
 *     use AsController;
 *
 *     public static function routes(Router $router): void
 *     {
 *         $router->put('posts/{post}', static::class)
 *             ->name('posts.update')
 *             ->can('update', 'post');
 *     }
 *
 *     public function authorize(Post $post): bool
 *     {
 *         return $post->user_id === auth()->id();
 *     }
 *
 *     public function handle(ActionRequest $request, Post $post): Post
 *     {
 *         $post->update($request->validated());
 *
 *         return $post;
 *     }
 * }
 * ```
 * @example Using getControllerMiddleware() for action-specific middleware
 * ```php
 * class AdminAction extends Actions
 * {
 *     use AsController;
 *
 *     public static function routes(Router $router): void
 *     {
 *         $router->get('admin/dashboard', static::class)
 *             ->name('admin.dashboard');
 *     }
 *
 *     public function getControllerMiddleware(): array
 *     {
 *         return ['auth', 'verified', 'role:admin'];
 *     }
 *
 *     public function handle(): array
 *     {
 *         return ['data' => 'Admin dashboard'];
 *     }
 * }
 * ```
 * @example Using jsonResponse() and htmlResponse() for different response types
 * ```php
 * class ShowPost extends Actions
 * {
 *     use AsController;
 *
 *     public static function routes(Router $router): void
 *     {
 *         $router->get('posts/{post}', static::class)->name('posts.show');
 *     }
 *
 *     public function jsonResponse(Post $post, Request $request): JsonResource
 *     {
 *         return new PostResource($post);
 *     }
 *
 *     public function htmlResponse(Post $post, Request $request): View
 *     {
 *         return view('posts.show', ['post' => $post]);
 *     }
 *
 *     public function handle(Post $post): Post
 *     {
 *         return $post;
 *     }
 * }
 * ```
 * @example API versioning with route prefix
 * ```php
 * class CreateUser extends Actions
 * {
 *     use AsController;
 *
 *     public static function routes(Router $router): void
 *     {
 *         $router->prefix(api_version())
 *             ->post('users', static::class)
 *             ->name('api.users.create')
 *             ->can('create', User::class);
 *     }
 *
 *     public function handle(array $data): User
 *     {
 *         return User::create($data);
 *     }
 * }
 * ```
 */
trait AsController
{
    /**
     * Make the action invokable as a controller.
     *
     * When Laravel resolves this action as a controller, it will call handle()
     * with the resolved route parameters and request.
     *
     * @param  mixed  ...$arguments  Route parameters and request
     * @return mixed The result of handle()
     *
     * @see static::handle()
     */
    public function __invoke(mixed ...$arguments): mixed
    {
        return $this->handle(...$arguments);
    }

    /**
     * Get middleware for this controller action.
     *
     * This empty method is required to enable controller middleware on the action.
     * Override getControllerMiddleware() to provide middleware instead.
     *
     * @return array<string> Empty array by default
     *
     * @see https://github.com/lorisleiva/laravel-actions/issues/199
     */
    public function getMiddleware(): array
    {
        return [];
    }
}
