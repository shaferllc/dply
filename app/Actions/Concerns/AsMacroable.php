<?php

namespace App\Actions\Concerns;

use Illuminate\Support\Macroable;
use Illuminate\Support\Traits\Macroable as LaravelMacroable;

/**
 * Makes actions macroable for dynamic method addition at runtime.
 *
 * This trait enables actions to have methods added dynamically using Laravel's
 * Macroable functionality. Macros allow you to extend action functionality
 * without modifying the action class itself, making actions more flexible
 * and extensible.
 *
 * How it works:
 * - Actions using AsMacroable can have methods added dynamically
 * - Use `ActionClass::macro('methodName', $callable)` to register macros
 * - Macros can be called on action instances like regular methods
 * - Macros have access to the action instance via `$this`
 * - Macros are shared across all instances of the action class
 *
 * Benefits:
 * - Add methods to actions without modifying the class
 * - Extend functionality at runtime
 * - Create reusable method extensions
 * - Works with Laravel's Macroable system
 * - Macros can access action properties and methods
 * - Useful for plugin-like functionality
 *
 * Note: This is NOT a decorator pattern. This is a direct use of Laravel's
 * Macroable trait, which provides runtime method addition capabilities.
 * It doesn't intercept execution or wrap actions - it simply allows
 * dynamic method registration.
 *
 * Does it need to be a decorator?
 * No. The current approach works well because:
 * - It uses Laravel's built-in Macroable trait directly
 * - Macros are added at the class level, not instance level
 * - No execution interception is needed
 * - The trait pattern is simpler for this use case
 *
 * A decorator would only be needed if you wanted to automatically
 * apply macros to all actions or add macro-related behavior. The current
 * approach gives you explicit control over macro registration.
 *
 * @example
 * // ============================================
 * // Example 1: Basic Macro Registration
 * // ============================================
 * class ProcessData extends Actions
 * {
 *     use AsMacroable;
 *
 *     public function handle(array $data): array
 *     {
 *         return $data;
 *     }
 * }
 *
 * // Register macros:
 * ProcessData::macro('transform', function ($data) {
 *     return array_map('strtoupper', $data);
 * });
 *
 * ProcessData::macro('validate', function ($data) {
 *     return ! empty($data);
 * });
 *
 * // Usage:
 * $action = ProcessData::make();
 * $transformed = $action->transform(['hello', 'world']); // ['HELLO', 'WORLD']
 * $isValid = $action->validate(['test']); // true
 * @example
 * // ============================================
 * // Example 2: Macros with Action Instance Access
 * // ============================================
 * class UserAction extends Actions
 * {
 *     use AsMacroable;
 *
 *     public function __construct(
 *         public User $user
 *     ) {}
 *
 *     public function handle(): void
 *     {
 *         // Action logic
 *     }
 * }
 *
 * // Register macro that uses action instance:
 * UserAction::macro('sendWelcomeEmail', function () {
 *     // $this refers to the action instance
 *     Mail::to($this->user)->send(new WelcomeEmail($this->user));
 * });
 *
 * // Usage:
 * $action = UserAction::make($user);
 * $action->sendWelcomeEmail(); // Sends email to $user
 * @example
 * // ============================================
 * // Example 3: Macros with Parameters
 * // ============================================
 * class DataProcessor extends Actions
 * {
 *     use AsMacroable;
 *
 *     public function handle(array $data): array
 *     {
 *         return $data;
 *     }
 * }
 *
 * // Register macro with parameters:
 * DataProcessor::macro('format', function ($data, string $format = 'json') {
 *     return match ($format) {
 *         'json' => json_encode($data),
 *         'xml' => $this->arrayToXml($data),
 *         'csv' => $this->arrayToCsv($data),
 *         default => $data,
 *     };
 * });
 *
 * // Usage:
 * $action = DataProcessor::make();
 * $json = $action->format(['key' => 'value'], 'json');
 * $xml = $action->format(['key' => 'value'], 'xml');
 * @example
 * // ============================================
 * // Example 4: Conditional Macros
 * // ============================================
 * class ConditionalAction extends Actions
 * {
 *     use AsMacroable;
 *
 *     public function handle(): void
 *     {
 *         // Action logic
 *     }
 * }
 *
 * // Register macro conditionally:
 * if (app()->environment('local')) {
 *     ConditionalAction::macro('debug', function ($message) {
 *         \Log::debug("Action debug: {$message}", [
 *             'action' => get_class($this),
 *         ]);
 *     });
 * }
 *
 * // Usage (only available in local environment):
 * $action = ConditionalAction::make();
 * if (method_exists($action, 'debug')) {
 *     $action->debug('Test message');
 * }
 * @example
 * // ============================================
 * // Example 5: Macros in Service Providers
 * // ============================================
 * class ActionServiceProvider extends ServiceProvider
 * {
 *     public function boot(): void
 *     {
 *         // Register macros for actions
 *         ProcessOrder::macro('notifyCustomer', function () {
 *             $this->order->customer->notify(new OrderProcessed($this->order));
 *         });
 *
 *         ProcessOrder::macro('logActivity', function (string $activity) {
 *             ActivityLog::create([
 *                 'order_id' => $this->order->id,
 *                 'activity' => $activity,
 *                 'user_id' => auth()->id(),
 *             ]);
 *         });
 *     }
 * }
 *
 * // Usage:
 * $action = ProcessOrder::make($order);
 * $action->notifyCustomer();
 * $action->logActivity('Order processed');
 * @example
 * // ============================================
 * // Example 6: Macros with Return Values
 * // ============================================
 * class Calculator extends Actions
 * {
 *     use AsMacroable;
 *
 *     public function handle(int $a, int $b): int
 *     {
 *         return $a + $b;
 *     }
 * }
 *
 * // Register calculation macros:
 * Calculator::macro('multiply', function (int $a, int $b): int {
 *     return $a * $b;
 * });
 *
 * Calculator::macro('divide', function (int $a, int $b): float {
 *     return $b !== 0 ? $a / $b : 0;
 * });
 *
 * // Usage:
 * $calc = Calculator::make();
 * $sum = $calc->handle(5, 3); // 8
 * $product = $calc->multiply(5, 3); // 15
 * $quotient = $calc->divide(10, 2); // 5.0
 * @example
 * // ============================================
 * // Example 7: Macros for Data Transformation
 * // ============================================
 * class DataTransformer extends Actions
 * {
 *     use AsMacroable;
 *
 *     public function handle(array $data): array
 *     {
 *         return $data;
 *     }
 * }
 *
 * // Register transformation macros:
 * DataTransformer::macro('toSnakeCase', function (array $data): array {
 *     return array_combine(
 *         array_map(fn ($key) => Str::snake($key), array_keys($data)),
 *         array_values($data)
 *     );
 * });
 *
 * DataTransformer::macro('toCamelCase', function (array $data): array {
 *     return array_combine(
 *         array_map(fn ($key) => Str::camel($key), array_keys($data)),
 *         array_values($data)
 *     );
 * });
 *
 * // Usage:
 * $transformer = DataTransformer::make();
 * $snakeData = $transformer->toSnakeCase(['firstName' => 'John', 'lastName' => 'Doe']);
 * // ['first_name' => 'John', 'last_name' => 'Doe']
 * @example
 * // ============================================
 * // Example 8: Macros for Validation
 * // ============================================
 * class ValidatorAction extends Actions
 * {
 *     use AsMacroable;
 *
 *     public function handle(array $data): array
 *     {
 *         return $data;
 *     }
 * }
 *
 * // Register validation macros:
 * ValidatorAction::macro('validateEmail', function (string $email): bool {
 *     return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
 * });
 *
 * ValidatorAction::macro('validateRequired', function (array $data, array $fields): bool {
 *     foreach ($fields as $field) {
 *         if (! isset($data[$field]) || empty($data[$field])) {
 *             return false;
 *         }
 *     }
 *
 *     return true;
 * });
 *
 * // Usage:
 * $validator = ValidatorAction::make();
 * $isValid = $validator->validateEmail('user@example.com'); // true
 * $hasRequired = $validator->validateRequired(['name' => 'John'], ['name', 'email']); // false
 * @example
 * // ============================================
 * // Example 9: Macros for Caching
 * // ============================================
 * class CacheableAction extends Actions
 * {
 *     use AsMacroable;
 *
 *     public function handle(string $key): mixed
 *     {
 *         return Cache::get($key);
 *     }
 * }
 *
 * // Register caching macros:
 * CacheableAction::macro('remember', function (string $key, callable $callback, int $ttl = 3600) {
 *     return Cache::remember($key, $ttl, $callback);
 * });
 *
 * CacheableAction::macro('forget', function (string $key): bool {
 *     return Cache::forget($key);
 * });
 *
 * // Usage:
 * $cache = CacheableAction::make();
 * $value = $cache->remember('key', fn () => expensiveOperation(), 7200);
 * $cache->forget('key');
 * @example
 * // ============================================
 * // Example 10: Macros for API Integration
 * // ============================================
 * class ApiAction extends Actions
 * {
 *     use AsMacroable;
 *
 *     public function handle(string $endpoint): array
 *     {
 *         return Http::get($endpoint)->json();
 *     }
 * }
 *
 * // Register API macros:
 * ApiAction::macro('post', function (string $endpoint, array $data): array {
 *     return Http::post($endpoint, $data)->json();
 * });
 *
 * ApiAction::macro('withAuth', function (string $endpoint, string $token): array {
 *     return Http::withToken($token)->get($endpoint)->json();
 * });
 *
 * // Usage:
 * $api = ApiAction::make();
 * $data = $api->post('https://api.example.com/data', ['key' => 'value']);
 * $authData = $api->withAuth('https://api.example.com/protected', $token);
 * @example
 * // ============================================
 * // Example 11: Macros for File Operations
 * // ============================================
 * class FileAction extends Actions
 * {
 *     use AsMacroable;
 *
 *     public function handle(string $path): string
 *     {
 *         return file_get_contents($path);
 *     }
 * }
 *
 * // Register file operation macros:
 * FileAction::macro('write', function (string $path, string $content): bool {
 *     return file_put_contents($path, $content) !== false;
 * });
 *
 * FileAction::macro('exists', function (string $path): bool {
 *     return file_exists($path);
 * });
 *
 * FileAction::macro('delete', function (string $path): bool {
 *     return unlink($path);
 * });
 *
 * // Usage:
 * $file = FileAction::make();
 * $file->write('/path/to/file.txt', 'content');
 * $exists = $file->exists('/path/to/file.txt'); // true
 * $file->delete('/path/to/file.txt');
 * @example
 * // ============================================
 * // Example 12: Macros for Database Operations
 * // ============================================
 * class DatabaseAction extends Actions
 * {
 *     use AsMacroable;
 *
 *     public function handle(string $table): \Illuminate\Support\Collection
 *     {
 *         return \DB::table($table)->get();
 *     }
 * }
 *
 * // Register database macros:
 * DatabaseAction::macro('find', function (string $table, int $id): ?object {
 *     return \DB::table($table)->find($id);
 * });
 *
 * DatabaseAction::macro('count', function (string $table): int {
 *     return \DB::table($table)->count();
 * });
 *
 * // Usage:
 * $db = DatabaseAction::make();
 * $user = $db->find('users', 1);
 * $count = $db->count('users');
 * @example
 * // ============================================
 * // Example 13: Macros for String Manipulation
 * // ============================================
 * class StringAction extends Actions
 * {
 *     use AsMacroable;
 *
 *     public function handle(string $text): string
 *     {
 *         return $text;
 *     }
 * }
 *
 * // Register string manipulation macros:
 * StringAction::macro('slugify', function (string $text): string {
 *     return Str::slug($text);
 * });
 *
 * StringAction::macro('truncate', function (string $text, int $length = 100): string {
 *     return Str::limit($text, $length);
 * });
 *
 * StringAction::macro('pluralize', function (string $word): string {
 *     return Str::plural($word);
 * });
 *
 * // Usage:
 * $string = StringAction::make();
 * $slug = $string->slugify('Hello World'); // 'hello-world'
 * $truncated = $string->truncate('Long text here', 10); // 'Long text...'
 * $plural = $string->pluralize('user'); // 'users'
 * @example
 * // ============================================
 * // Example 14: Macros for Date/Time Operations
 * // ============================================
 * class DateAction extends Actions
 * {
 *     use AsMacroable;
 *
 *     public function handle(\DateTime $date): \DateTime
 *     {
 *         return $date;
 *     }
 * }
 *
 * // Register date macros:
 * DateAction::macro('format', function (\DateTime $date, string $format = 'Y-m-d'): string {
 *     return $date->format($format);
 * });
 *
 * DateAction::macro('addDays', function (\DateTime $date, int $days): \DateTime {
 *     $newDate = clone $date;
 *     $newDate->modify("+{$days} days");
 *
 *     return $newDate;
 * });
 *
 * DateAction::macro('isWeekend', function (\DateTime $date): bool {
 *     return in_array($date->format('N'), ['6', '7']);
 * });
 *
 * // Usage:
 * $date = DateAction::make();
 * $formatted = $date->format(new \DateTime(), 'Y-m-d H:i:s');
 * $future = $date->addDays(new \DateTime(), 7);
 * $isWeekend = $date->isWeekend(new \DateTime());
 * @example
 * // ============================================
 * // Example 15: Real-World Usage - Extensible Payment Processor
 * // ============================================
 * class PaymentProcessor extends Actions
 * {
 *     use AsMacroable;
 *
 *     public function __construct(
 *         public Order $order
 *     ) {}
 *
 *     public function handle(): Payment
 *     {
 *         // Base payment processing
 *         return Payment::create([
 *             'order_id' => $this->order->id,
 *             'amount' => $this->order->total,
 *             'status' => 'pending',
 *         ]);
 *     }
 * }
 *
 * // Register payment provider-specific macros in ServiceProvider:
 * PaymentProcessor::macro('processStripe', function (string $token): Payment {
 *     $payment = $this->handle();
 *     $result = StripeService::charge($this->order->total, $token);
 *     $payment->update(['status' => $result->succeeded ? 'completed' : 'failed']);
 *
 *     return $payment;
 * });
 *
 * PaymentProcessor::macro('processPayPal', function (string $paymentId): Payment {
 *     $payment = $this->handle();
 *     $result = PayPalService::capture($paymentId);
 *     $payment->update(['status' => $result->succeeded ? 'completed' : 'failed']);
 *
 *     return $payment;
 * });
 *
 * PaymentProcessor::macro('refund', function (Payment $payment): bool {
 *     return match ($payment->provider) {
 *         'stripe' => StripeService::refund($payment->transaction_id),
 *         'paypal' => PayPalService::refund($payment->transaction_id),
 *         default => false,
 *     };
 * });
 *
 * // Usage:
 * $processor = PaymentProcessor::make($order);
 *
 * // Use Stripe macro
 * $payment = $processor->processStripe($stripeToken);
 *
 * // Use PayPal macro
 * $payment = $processor->processPayPal($paypalPaymentId);
 *
 * // Use refund macro
 * $processor->refund($payment);
 *
 * @see LaravelMacroable
 * @see Macroable
 */
trait AsMacroable
{
    use LaravelMacroable;
}
