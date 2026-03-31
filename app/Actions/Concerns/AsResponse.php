<?php

namespace App\Actions\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/**
 * Provides response builders for actions.
 *
 * Adds response-building capabilities to actions, allowing them to automatically
 * convert action results into HTTP responses (JSON, redirects, or plain responses).
 * Handles both success and error cases with customizable response formatting.
 *
 * How it works:
 * - Provides `response()` method that wraps `handle()` in try-catch
 * - Automatically converts results to appropriate response types
 * - Handles exceptions and converts them to error responses
 * - Detects JSON vs HTML requests and responds accordingly
 * - Provides utility methods for building responses (json, redirect)
 * - Allows custom success/error response formatting
 *
 * Benefits:
 * - Automatic response conversion
 * - Exception handling in responses
 * - JSON/HTML request detection
 * - Customizable response formatting
 * - Consistent error responses
 * - Easy redirect support
 *
 * Note: This is NOT a decorator - it provides utility methods that
 * actions can call explicitly. The `response()` method is opt-in and
 * provides an alternative way to execute actions and get responses.
 *
 * Does it need to be a decorator?
 * No. The current trait-based approach works well because:
 * - It provides a separate `response()` method (doesn't override `handle()`)
 * - Actions explicitly call `->response()` when they want a response
 * - It provides utility methods (json, redirect, successResponse, errorResponse)
 * - The trait pattern is simpler for this use case
 *
 * A decorator would only be needed if you wanted to automatically
 * convert all action results to responses, but the current approach
 * gives you explicit control over when to use responses.
 *
 * @example
 * // Basic usage - automatic response conversion:
 * class CreateUser extends Actions
 * {
 *     use AsResponse;
 *
 *     public function handle(array $data): User
 *     {
 *         return User::create($data);
 *     }
 * }
 *
 * // Usage in controller:
 * class UserController extends Controller
 * {
 *     public function store(Request $request)
 *     {
 *         return CreateUser::run($request->validated())->response();
 *         // Automatically returns JSON for API requests, or plain response for web
 *     }
 * }
 * @example
 * // Custom success response:
 * class CreatePost extends Actions
 * {
 *     use AsResponse;
 *
 *     public function handle(array $data): Post
 *     {
 *         return Post::create($data);
 *     }
 *
 *     public function successResponse($result): JsonResponse
 *     {
 *         return $this->json([
 *             'message' => 'Post created successfully',
 *             'data' => $result,
 *             'id' => $result->id,
 *         ], 201);
 *     }
 * }
 *
 * // Usage:
 * return CreatePost::run($data)->response();
 * // Returns: {"message": "Post created successfully", "data": {...}, "id": 123} with 201 status
 * @example
 * // Custom error response:
 * class ProcessPayment extends Actions
 * {
 *     use AsResponse;
 *
 *     public function handle(Order $order, float $amount): void
 *     {
 *         if ($order->balance < $amount) {
 *             throw new \RuntimeException('Insufficient balance');
 *         }
 *
 *         PaymentGateway::charge($order, $amount);
 *     }
 *
 *     public function errorResponse(\Throwable $exception): JsonResponse
 *     {
 *         $statusCode = match (true) {
 *             str_contains($exception->getMessage(), 'Insufficient') => 402,
 *             str_contains($exception->getMessage(), 'Invalid') => 400,
 *             default => 500,
 *         };
 *
 *         return $this->json([
 *             'error' => $exception->getMessage(),
 *             'code' => $exception->getCode(),
 *         ], $statusCode);
 *     }
 * }
 *
 * // Usage:
 * return ProcessPayment::run($order, 100.00)->response();
 * // Returns appropriate error response based on exception type
 * @example
 * // Redirect on success:
 * class UpdateProfile extends Actions
 * {
 *     use AsResponse;
 *
 *     public function handle(User $user, array $data): User
 *     {
 *         $user->update($data);
 *
 *         return $user;
 *     }
 *
 *     public function successResponse($result): RedirectResponse
 *     {
 *         return $this->redirect('profile.show', ['user' => $result->id]);
 *     }
 * }
 *
 * // Usage:
 * return UpdateProfile::run($user, $data)->response();
 * // Redirects to profile.show route after successful update
 * @example
 * // Different responses for JSON vs HTML:
 * class DeleteItem extends Actions
 * {
 *     use AsResponse;
 *
 *     public function handle(Item $item): void
 *     {
 *         $item->delete();
 *     }
 *
 *     public function successResponse($result): Response|JsonResponse
 *     {
 *         if (request()->expectsJson()) {
 *             return $this->json(['message' => 'Item deleted'], 200);
 *         }
 *
 *         return redirect()->back()->with('success', 'Item deleted');
 *     }
 * }
 *
 * // Usage:
 * // API request: returns JSON
 * // Web request: returns redirect with flash message
 * return DeleteItem::run($item)->response();
 * @example
 * // Using with validation errors:
 * class CreateOrder extends Actions
 * {
 *     use AsResponse;
 *
 *     public function handle(array $data): Order
 *     {
 *         $validated = validator($data, [
 *             'items' => 'required|array',
 *             'total' => 'required|numeric|min:0',
 *         ])->validate();
 *
 *         return Order::create($validated);
 *     }
 *
 *     public function errorResponse(\Throwable $exception): JsonResponse
 *     {
 *         if ($exception instanceof \Illuminate\Validation\ValidationException) {
 *             return $this->json([
 *                 'message' => 'Validation failed',
 *                 'errors' => $exception->errors(),
 *             ], 422);
 *         }
 *
 *         return $this->json([
 *             'error' => $exception->getMessage(),
 *         ], 500);
 *     }
 * }
 *
 * // Usage:
 * return CreateOrder::run($data)->response();
 * // Returns 422 with validation errors if validation fails
 * @example
 * // Combining with other concerns:
 * class ProcessImport extends Actions
 * {
 *     use AsResponse;
 *     use AsRetry;
 *     use AsTimeout;
 *
 *     public function handle(string $filePath): array
 *     {
 *         // Process import file
 *         return ImportProcessor::process($filePath);
 *     }
 *
 *     public function successResponse($result): JsonResponse
 *     {
 *         return $this->json([
 *             'message' => 'Import completed',
 *             'processed' => $result['count'],
 *             'retries' => $result->_retry['attempts'] ?? 1,
 *         ], 200);
 *     }
 * }
 *
 * // Usage:
 * return ProcessImport::run($filePath)->response();
 * // Combines retry, timeout, and response building
 * @example
 * // API resource transformation in response:
 * class GetUserProfile extends Actions
 * {
 *     use AsResponse;
 *
 *     public function handle(User $user): array
 *     {
 *         return [
 *             'id' => $user->id,
 *             'name' => $user->name,
 *             'email' => $user->email,
 *             'created_at' => $user->created_at->toIso8601String(),
 *         ];
 *     }
 *
 *     public function successResponse($result): JsonResponse
 *     {
 *         return $this->json([
 *             'data' => $result,
 *             'meta' => [
 *                 'timestamp' => now()->toIso8601String(),
 *             ],
 *         ], 200);
 *     }
 * }
 *
 * // Usage:
 * return GetUserProfile::run($user)->response();
 * // Returns formatted API response with metadata
 * @example
 * // Conditional redirect based on result:
 * class CompleteCheckout extends Actions
 * {
 *     use AsResponse;
 *
 *     public function handle(Order $order): Order
 *     {
 *         $order->complete();
 *
 *         return $order;
 *     }
 *
 *     public function successResponse($result): RedirectResponse|JsonResponse
 *     {
 *         if (request()->expectsJson()) {
 *             return $this->json([
 *                 'order' => $result,
 *                 'redirect' => route('orders.show', $result),
 *             ], 200);
 *         }
 *
 *         // Redirect to order confirmation page
 *         return $this->redirect('orders.show', ['order' => $result->id]);
 *     }
 * }
 *
 * // Usage:
 * return CompleteCheckout::run($order)->response();
 * // Redirects web users, returns JSON with redirect URL for API users
 * @example
 * // Error response with logging:
 * class ProcessWebhook extends Actions
 * {
 *     use AsResponse;
 *
 *     public function handle(array $payload): void
 *     {
 *         WebhookProcessor::process($payload);
 *     }
 *
 *     public function errorResponse(\Throwable $exception): JsonResponse
 *     {
 *         // Log error for debugging
 *         \Log::error('Webhook processing failed', [
 *             'exception' => get_class($exception),
 *             'message' => $exception->getMessage(),
 *             'trace' => $exception->getTraceAsString(),
 *         ]);
 *
 *         // Return sanitized error to client
 *         return $this->json([
 *             'error' => 'Webhook processing failed',
 *             'request_id' => request()->header('X-Request-ID'),
 *         ], 500);
 *     }
 * }
 *
 * // Usage:
 * return ProcessWebhook::run($payload)->response();
 * // Logs detailed error, returns sanitized response to client
 * @example
 * // Streaming response for large data:
 * class ExportData extends Actions
 * {
 *     use AsResponse;
 *
 *     public function handle(array $filters): \Generator
 *     {
 *         foreach (Data::where($filters)->cursor() as $item) {
 *             yield $item->toArray();
 *         }
 *     }
 *
 *     public function successResponse($result): Response
 *     {
 *         return response()->stream(function () use ($result) {
 *             foreach ($result as $item) {
 *                 echo json_encode($item)."\n";
 *                 flush();
 *             }
 *         }, 200, [
 *             'Content-Type' => 'application/x-ndjson',
 *             'X-Accel-Buffering' => 'no',
 *         ]);
 *     }
 * }
 *
 * // Usage:
 * return ExportData::run($filters)->response();
 * // Streams large datasets as NDJSON
 * @example
 * // File download response:
 * class GenerateReport extends Actions
 * {
 *     use AsResponse;
 *
 *     public function handle(array $options): string
 *     {
 *         $filePath = ReportGenerator::generate($options);
 *
 *         return $filePath;
 *     }
 *
 *     public function successResponse($result): Response
 *     {
 *         return response()->download($result, 'report.pdf', [
 *             'Content-Type' => 'application/pdf',
 *         ]);
 *     }
 * }
 *
 * // Usage:
 * return GenerateReport::run($options)->response();
 * // Downloads the generated report file
 * @example
 * // Using response() in Livewire components:
 * class CreateTeam extends Actions
 * {
 *     use AsResponse;
 *
 *     public function handle(array $data): Team
 *     {
 *         return Team::create($data);
 *     }
 *
 *     public function successResponse($result): JsonResponse
 *     {
 *         return $this->json([
 *             'team' => $result,
 *             'redirect' => route('teams.show', $result),
 *         ], 201);
 *     }
 * }
 *
 * // Livewire Component:
 * class CreateTeamForm extends Component
 * {
 *     public array $form = [];
 *
 *     public function save(): void
 *     {
 *         $response = CreateTeam::run($this->form)->response();
 *
 *         if ($response instanceof JsonResponse) {
 *             $data = $response->getData(true);
 *             $this->dispatch('team-created', team: $data['team']);
 *             $this->redirect($data['redirect']);
 *         }
 *     }
 *
 *     public function render(): View
 *     {
 *         return view('livewire.create-team-form');
 *     }
 * }
 *
 * // Response building works seamlessly with Livewire
 */
trait AsResponse
{
    public function response(): Response|JsonResponse|RedirectResponse
    {
        try {
            $result = $this->handle(...func_get_args());

            return $this->successResponse($result);
        } catch (\Throwable $e) {
            return $this->errorResponse($e);
        }
    }

    protected function successResponse($result): Response|JsonResponse|RedirectResponse
    {
        if ($this->hasMethod('successResponse')) {
            return $this->callMethod('successResponse', [$result]);
        }

        if (request()->expectsJson()) {
            return $this->json(['data' => $result], 200);
        }

        return response()->make('', 200);
    }

    protected function errorResponse(\Throwable $exception): Response|JsonResponse|RedirectResponse
    {
        if ($this->hasMethod('errorResponse')) {
            return $this->callMethod('errorResponse', [$exception]);
        }

        if (request()->expectsJson()) {
            return $this->json(['error' => $exception->getMessage()], 500);
        }

        return response()->make($exception->getMessage(), 500);
    }

    protected function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status);
    }

    protected function redirect(string $route, array $parameters = []): RedirectResponse
    {
        return redirect()->route($route, $parameters);
    }
}
