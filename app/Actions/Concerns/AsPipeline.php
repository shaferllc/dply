<?php

namespace App\Actions\Concerns;

/**
 * Allows actions to be used as pipeline pipes.
 *
 * Provides Laravel Pipeline capabilities for actions, allowing them to be used
 * as pipes in Laravel's Pipeline system. Works with PipelineDecorator to
 * automatically wrap actions when used in pipelines.
 *
 * How it works:
 * - PipelineDesignPattern recognizes actions using AsPipeline
 * - ActionManager wraps the action with PipelineDecorator
 * - When used in a pipeline, the decorator's __invoke() is called
 * - Decorator routes to asPipeline() method if exists, otherwise handle()
 * - Each pipe receives the passable and returns it (possibly modified)
 * - Pipeline passes result from one pipe to the next
 *
 * Benefits:
 * - Use actions as pipeline pipes
 * - Sequential processing of data
 * - Clean separation of concerns
 * - Easy to add/remove steps
 * - Works with Laravel's Pipeline system
 * - Can access action dependencies (services, repositories, etc.)
 *
 * Note: This trait works WITH a decorator (PipelineDecorator) that
 * automatically wraps actions when used in Laravel's Pipeline. The trait
 * is a marker that triggers the decorator, which handles the __invoke()
 * method required by Pipeline pipes.
 *
 * Pipeline Methods:
 * - `handle($passable)`: Default method called by pipeline (if asPipeline() doesn't exist)
 * - `asPipeline($passable)`: Optional method for custom pipeline handling
 * - Both methods receive the passable (data being piped) and should return it
 *
 * @example
 * // Basic usage - order processing pipeline:
 * class ValidateOrder extends Actions
 * {
 *     use AsPipeline;
 *
 *     public function handle(Order $order): Order
 *     {
 *         // Validate order
 *         validator($order->toArray(), [
 *             'items' => 'required|array',
 *             'total' => 'required|numeric|min:0',
 *         ])->validate();
 *
 *         return $order;
 *     }
 * }
 *
 * class CalculateTotal extends Actions
 * {
 *     use AsPipeline;
 *
 *     public function handle(Order $order): Order
 *     {
 *         $order->total = $order->items->sum('price');
 *
 *         return $order;
 *     }
 * }
 *
 * class ApplyDiscount extends Actions
 * {
 *     use AsPipeline;
 *
 *     public function handle(Order $order): Order
 *     {
 *         if ($order->hasCoupon()) {
 *             $order->discount = $order->total * 0.1; // 10% discount
 *             $order->total -= $order->discount;
 *         }
 *
 *         return $order;
 *     }
 * }
 *
 * // Usage:
 * $order = Pipeline::send($order)
 *     ->through([
 *         ValidateOrder::class,
 *         CalculateTotal::class,
 *         ApplyDiscount::class,
 *     ])
 *     ->thenReturn();
 * @example
 * // Using asPipeline() method for custom handling:
 * class TransformData extends Actions
 * {
 *     use AsPipeline;
 *
 *     public function asPipeline(array $data): array
 *     {
 *         // Custom pipeline handling
 *         $data['transformed'] = true;
 *         $data['timestamp'] = now()->toIso8601String();
 *
 *         return $data;
 *     }
 *
 *     // handle() method is ignored when asPipeline() exists
 *     public function handle(array $data): array
 *     {
 *         return $data;
 *     }
 * }
 *
 * // Usage:
 * $data = Pipeline::send($data)
 *     ->through([TransformData::class])
 *     ->thenReturn();
 * @example
 * // Pipeline with conditional processing:
 * class CheckInventory extends Actions
 * {
 *     use AsPipeline;
 *
 *     public function handle(Order $order): Order
 *     {
 *         foreach ($order->items as $item) {
 *             if (! $item->product->inStock($item->quantity)) {
 *                 throw new \Exception("Insufficient inventory for {$item->product->name}");
 *             }
 *         }
 *
 *         return $order;
 *     }
 * }
 *
 * class ReserveInventory extends Actions
 * {
 *     use AsPipeline;
 *
 *     public function handle(Order $order): Order
 *     {
 *         foreach ($order->items as $item) {
 *             $item->product->reserve($item->quantity);
 *         }
 *
 *         return $order;
 *     }
 * }
 *
 * // Usage:
 * $order = Pipeline::send($order)
 *     ->through([
 *         CheckInventory::class,
 *         ReserveInventory::class,
 *     ])
 *     ->thenReturn();
 * // If CheckInventory fails, ReserveInventory is not executed
 * @example
 * // Pipeline with data transformation:
 * class SanitizeInput extends Actions
 * {
 *     use AsPipeline;
 *
 *     public function handle(array $data): array
 *     {
 *         return array_map(fn ($value) => is_string($value) ? trim(strip_tags($value)) : $value, $data);
 *     }
 * }
 *
 * class ValidateInput extends Actions
 * {
 *     use AsPipeline;
 *
 *     public function handle(array $data): array
 *     {
 *         return validator($data, [
 *             'name' => 'required|string|max:255',
 *             'email' => 'required|email',
 *         ])->validate();
 *     }
 * }
 *
 * class NormalizeInput extends Actions
 * {
 *     use AsPipeline;
 *
 *     public function handle(array $data): array
 *     {
 *         $data['email'] = strtolower($data['email']);
 *         $data['name'] = ucwords($data['name']);
 *
 *         return $data;
 *     }
 * }
 *
 * // Usage:
 * $data = Pipeline::send($rawInput)
 *     ->through([
 *         SanitizeInput::class,
 *         ValidateInput::class,
 *         NormalizeInput::class,
 *     ])
 *     ->thenReturn();
 * @example
 * // Pipeline with dependencies:
 * class ProcessPayment extends Actions
 * {
 *     use AsPipeline;
 *
 *     public function __construct(
 *         public PaymentService $paymentService
 *     ) {}
 *
 *     public function handle(Order $order): Order
 *     {
 *         $payment = $this->paymentService->charge($order);
 *         $order->payment_id = $payment->id;
 *
 *         return $order;
 *     }
 * }
 *
 * class SendConfirmation extends Actions
 * {
 *     use AsPipeline;
 *
 *     public function __construct(
 *         public NotificationService $notificationService
 *     ) {}
 *
 *     public function handle(Order $order): Order
 *     {
 *         $this->notificationService->sendOrderConfirmation($order);
 *
 *         return $order;
 *     }
 * }
 *
 * // Usage:
 * $order = Pipeline::send($order)
 *     ->through([
 *         ProcessPayment::class,
 *         SendConfirmation::class,
 *     ])
 *     ->thenReturn();
 * // Dependencies are automatically injected
 * @example
 * // Pipeline with early termination:
 * class CheckPermissions extends Actions
 * {
 *     use AsPipeline;
 *
 *     public function handle(Request $request): Request
 *     {
 *         if (! auth()->user()->can('access')) {
 *             throw new \Illuminate\Auth\Access\AuthorizationException('Access denied');
 *         }
 *
 *         return $request;
 *     }
 * }
 *
 * class ProcessRequest extends Actions
 * {
 *     use AsPipeline;
 *
 *     public function handle(Request $request): Request
 *     {
 *         // This won't run if CheckPermissions throws exception
 *         return $request;
 *     }
 * }
 *
 * // Usage:
 * try {
 *     $request = Pipeline::send($request)
 *         ->through([
 *             CheckPermissions::class,
 *             ProcessRequest::class,
 *         ])
 *         ->thenReturn();
 * } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
 *     // Handle early termination
 * }
 * @example
 * // Pipeline with then() callback:
 * class CreateUser extends Actions
 * {
 *     use AsPipeline;
 *
 *     public function handle(array $data): User
 *     {
 *         return User::create($data);
 *     }
 * }
 *
 * class SendWelcomeEmail extends Actions
 * {
 *     use AsPipeline;
 *
 *     public function handle(User $user): User
 *     {
 *         Mail::to($user)->send(new WelcomeMail);
 *
 *         return $user;
 *     }
 * }
 *
 * // Usage with then() callback:
 * $user = Pipeline::send($userData)
 *     ->through([
 *         CreateUser::class,
 *         SendWelcomeEmail::class,
 *     ])
 *     ->then(function (User $user) {
 *         // Called after all pipes complete
 *         \Log::info("User {$user->id} created and welcomed");
 *
 *         return $user;
 *     });
 * @example
 * // Pipeline with different return types:
 * class ParseJson extends Actions
 * {
 *     use AsPipeline;
 *
 *     public function handle(string $json): array
 *     {
 *         return json_decode($json, true);
 *     }
 * }
 *
 * class ValidateData extends Actions
 * {
 *     use AsPipeline;
 *
 *     public function handle(array $data): array
 *     {
 *         return validator($data, [
 *             'name' => 'required',
 *             'email' => 'required|email',
 *         ])->validate();
 *     }
 * }
 *
 * class CreateModel extends Actions
 * {
 *     use AsPipeline;
 *
 *     public function handle(array $data): User
 *     {
 *         return User::create($data);
 *     }
 * }
 *
 * // Usage:
 * $user = Pipeline::send($jsonString)
 *     ->through([
 *         ParseJson::class,      // string -> array
 *         ValidateData::class,   // array -> array
 *         CreateModel::class,    // array -> User
 *     ])
 *     ->thenReturn();
 * @example
 * // Pipeline with conditional pipes:
 * class ProcessOrder extends Actions
 * {
 *     use AsPipeline;
 *
 *     public function handle(Order $order): Order
 *     {
 *         $pipes = [
 *             ValidateOrder::class,
 *             CalculateTotal::class,
 *         ];
 *
 *         // Conditionally add discount pipe
 *         if ($order->hasCoupon()) {
 *             $pipes[] = ApplyDiscount::class;
 *         }
 *
 *         // Conditionally add tax pipe
 *         if ($order->requiresTax()) {
 *             $pipes[] = CalculateTax::class;
 *         }
 *
 *         $pipes[] = ProcessPayment::class;
 *
 *         return Pipeline::send($order)
 *             ->through($pipes)
 *             ->thenReturn();
 *     }
 * }
 * @example
 * // Pipeline combining with other concerns:
 * class ComprehensivePipe extends Actions
 * {
 *     use AsPipeline;
 *     use AsRetry;
 *     use AsTimeout;
 *
 *     public function handle(Order $order): Order
 *     {
 *         // Process order with retry and timeout
 *         return $order;
 *     }
 *
 *     public function getMaxRetries(): int
 *     {
 *         return 3;
 *     }
 * }
 *
 * // Usage:
 * $order = Pipeline::send($order)
 *     ->through([ComprehensivePipe::class])
 *     ->thenReturn();
 * // Combines pipeline with retry and timeout decorators
 * @example
 * // Pipeline with logging:
 * class LoggedPipe extends Actions
 * {
 *     use AsPipeline;
 *
 *     public function handle($passable)
 *     {
 *         \Log::info('Pipeline pipe executed', [
 *             'pipe' => get_class($this),
 *             'passable_type' => gettype($passable),
 *         ]);
 *
 *         // Process passable
 *         return $passable;
 *     }
 * }
 * @example
 * // Pipeline with middleware-like behavior:
 * class AuthenticateRequest extends Actions
 * {
 *     use AsPipeline;
 *
 *     public function handle(Request $request): Request
 *     {
 *         if (! auth()->check()) {
 *             throw new \Illuminate\Auth\AuthenticationException;
 *         }
 *
 *         return $request;
 *     }
 * }
 *
 * class AuthorizeRequest extends Actions
 * {
 *     use AsPipeline;
 *
 *     public function handle(Request $request): Request
 *     {
 *         if (! auth()->user()->can($request->route()->getName())) {
 *             throw new \Illuminate\Auth\Access\AuthorizationException;
 *         }
 *
 *         return $request;
 *     }
 * }
 *
 * class ProcessRequest extends Actions
 * {
 *     use AsPipeline;
 *
 *     public function handle(Request $request): Response
 *     {
 *         // Process authenticated and authorized request
 *         return response()->json(['success' => true]);
 *     }
 * }
 *
 * // Usage:
 * $response = Pipeline::send($request)
 *     ->through([
 *         AuthenticateRequest::class,
 *         AuthorizeRequest::class,
 *         ProcessRequest::class,
 *     ])
 *     ->thenReturn();
 */
trait AsPipeline
{
    // This trait is a marker trait.
    // The actual pipeline logic is handled by PipelineDecorator
    // which is automatically applied via PipelineDesignPattern.
    // Pipeline methods (handle or asPipeline) are defined on the action class.
}
