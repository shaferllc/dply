<?php

namespace App\Actions\Concerns;

/**
 * Provides factory pattern support for actions.
 *
 * This trait enables fluent factory methods for creating action instances with
 * pre-configured properties. It integrates with Laravel's service container to
 * resolve dependencies while allowing you to set custom attributes.
 *
 * Features:
 * - Fluent factory methods for action creation
 * - Property injection via make() method
 * - Immediate execution with create() method
 * - Service container integration
 * - Chainable factory methods
 *
 * Benefits:
 * - More readable action instantiation
 * - Flexible configuration options
 * - Fluent API for complex setups
 * - Dependency injection support
 * - Reduces boilerplate code
 *
 * Methods:
 * - `make(array $attributes = [])` - Create instance with attributes
 * - `create(...$arguments)` - Create instance and call handle()
 *
 * Note: When using `AsAction` (which includes `AsFactory`), the `create()` method
 * is aliased to `factoryCreate()` to avoid conflicts with interfaces that require
 * a non-static `create()` method (e.g., Laravel Fortify's `CreatesNewUsers`).
 * Use `factoryCreate()` when using `AsAction`, or use `create()` directly when
 * using `AsFactory` standalone.
 *
 * @example
 * // ============================================
 * // Example 1: Basic Factory Pattern
 * // ============================================
 * class CreateOrder extends Actions
 * {
 *     use AsFactory;
 *
 *     public function handle(array $items): Order
 *     {
 *         return Order::create([
 *             'items' => $items,
 *             'status' => 'pending',
 *         ]);
 *     }
 *
 *     // Factory methods
 *     public static function forUser(User $user): self
 *     {
 *         return static::make()->setUser($user);
 *     }
 *
 *     public static function withItems(array $items): self
 *     {
 *         return static::make()->setItems($items);
 *     }
 *
 *     public static function forUserWithItems(User $user, array $items): self
 *     {
 *         return static::forUser($user)->setItems($items);
 *     }
 * }
 *
 * // Usage:
 * $order = CreateOrder::forUser($user)
 *     ->withItems($items)
 *     ->handle();
 * @example
 * // ============================================
 * // Example 2: Using make() with Attributes
 * // ============================================
 * class ProcessPayment extends Actions
 * {
 *     use AsFactory;
 *
 *     public function __construct(
 *         public ?string $currency = 'USD',
 *         public ?float $fee = 0.0
 *     ) {}
 *
 *     public function handle(float $amount): Payment
 *     {
 *         return Payment::create([
 *             'amount' => $amount,
 *             'currency' => $this->currency,
 *             'fee' => $this->fee,
 *         ]);
 *     }
 * }
 *
 * // Usage:
 * $action = ProcessPayment::make(['currency' => 'EUR', 'fee' => 2.5]);
 * $payment = $action->handle(100.0);
 * @example
 * // ============================================
 * // Example 3: Using create() for Immediate Execution
 * // ============================================
 * class SendNotification extends Actions
 * {
 *     use AsFactory;
 *
 *     public function handle(string $message): void
 *     {
 *         // Send notification
 *     }
 * }
 *
 * // Usage:
 * SendNotification::create('Hello World');
 * // Equivalent to: SendNotification::make()->handle('Hello World');
 * @example
 * // ============================================
 * // Example 4: Chaining Factory Methods
 * // ============================================
 * class GenerateReport extends Actions
 * {
 *     use AsFactory;
 *
 *     protected ?User $user = null;
 *     protected ?\DateTime $startDate = null;
 *     protected ?\DateTime $endDate = null;
 *     protected string $format = 'pdf';
 *
 *     public function handle(): Report
 *     {
 *         return Report::generate([
 *             'user' => $this->user,
 *             'start_date' => $this->startDate,
 *             'end_date' => $this->endDate,
 *             'format' => $this->format,
 *         ]);
 *     }
 *
 *     public static function forUser(User $user): self
 *     {
 *         return static::make()->setUser($user);
 *     }
 *
 *     public function setUser(User $user): self
 *     {
 *         $this->user = $user;
 *         return $this;
 *     }
 *
 *     public function setDateRange(\DateTime $start, \DateTime $end): self
 *     {
 *         $this->startDate = $start;
 *         $this->endDate = $end;
 *         return $this;
 *     }
 *
 *     public function asPdf(): self
 *     {
 *         $this->format = 'pdf';
 *         return $this;
 *     }
 *
 *     public function asExcel(): self
 *     {
 *         $this->format = 'excel';
 *         return $this;
 *     }
 * }
 *
 * // Usage:
 * $report = GenerateReport::forUser($user)
 *     ->setDateRange($startDate, $endDate)
 *     ->asPdf()
 *     ->handle();
 * @example
 * // ============================================
 * // Example 5: Factory with Dependency Injection
 * // ============================================
 * class ProcessOrder extends Actions
 * {
 *     use AsFactory;
 *
 *     public function __construct(
 *         protected PaymentService $paymentService,
 *         protected EmailService $emailService
 *     ) {}
 *
 *     protected ?Order $order = null;
 *
 *     public function handle(Order $order): void
 *     {
 *         $this->order = $order;
 *         $this->paymentService->charge($order);
 *         $this->emailService->sendConfirmation($order);
 *     }
 *
 *     public static function withOrder(Order $order): self
 *     {
 *         return static::make()->setOrder($order);
 *     }
 *
 *     public function setOrder(Order $order): self
 *     {
 *         $this->order = $order;
 *         return $this;
 *     }
 * }
 *
 * // Usage:
 * // Dependencies are injected automatically via container
 * ProcessOrder::withOrder($order)->handle($order);
 * @example
 * // ============================================
 * // Example 6: Factory with Default Values
 * // ============================================
 * class CreatePost extends Actions
 * {
 *     use AsFactory;
 *
 *     protected string $status = 'draft';
 *     protected bool $published = false;
 *     protected ?User $author = null;
 *
 *     public function handle(array $data): Post
 *     {
 *         return Post::create([
 *             ...$data,
 *             'status' => $this->status,
 *             'published' => $this->published,
 *             'author_id' => $this->author?->id,
 *         ]);
 *     }
 *
 *     public static function asDraft(): self
 *     {
 *         return static::make()->setStatus('draft');
 *     }
 *
 *     public static function asPublished(): self
 *     {
 *         return static::make()->setStatus('published')->setPublished(true);
 *     }
 *
 *     public static function byAuthor(User $author): self
 *     {
 *         return static::make()->setAuthor($author);
 *     }
 *
 *     public function setStatus(string $status): self
 *     {
 *         $this->status = $status;
 *         return $this;
 *     }
 *
 *     public function setPublished(bool $published): self
 *     {
 *         $this->published = $published;
 *         return $this;
 *     }
 *
 *     public function setAuthor(User $author): self
 *     {
 *         $this->author = $author;
 *         return $this;
 *     }
 * }
 *
 * // Usage:
 * $post = CreatePost::asPublished()
 *     ->byAuthor($user)
 *     ->handle(['title' => 'My Post', 'content' => '...']);
 * @example
 * // ============================================
 * // Example 7: Factory with Multiple Parameters
 * // ============================================
 * class ScheduleTask extends Actions
 * {
 *     use AsFactory;
 *
 *     protected ?\DateTime $scheduledAt = null;
 *     protected ?string $timezone = null;
 *     protected ?int $priority = null;
 *
 *     public function handle(Task $task): void
 *     {
 *         $task->schedule([
 *             'scheduled_at' => $this->scheduledAt,
 *             'timezone' => $this->timezone,
 *             'priority' => $this->priority,
 *         ]);
 *     }
 *
 *     public static function at(\DateTime $dateTime): self
 *     {
 *         return static::make()->setScheduledAt($dateTime);
 *     }
 *
 *     public static function inTimezone(string $timezone): self
 *     {
 *         return static::make()->setTimezone($timezone);
 *     }
 *
 *     public static function withPriority(int $priority): self
 *     {
 *         return static::make()->setPriority($priority);
 *     }
 *
 *     public function setScheduledAt(\DateTime $dateTime): self
 *     {
 *         $this->scheduledAt = $dateTime;
 *         return $this;
 *     }
 *
 *     public function setTimezone(string $timezone): self
 *     {
 *         $this->timezone = $timezone;
 *         return $this;
 *     }
 *
 *     public function setPriority(int $priority): self
 *     {
 *         $this->priority = $priority;
 *         return $this;
 *     }
 * }
 *
 * // Usage:
 * ScheduleTask::at($dateTime)
 *     ->inTimezone('America/New_York')
 *     ->withPriority(10)
 *     ->handle($task);
 * @example
 * // ============================================
 * // Example 8: Factory with Conditional Logic
 * // ============================================
 * class ProcessRefund extends Actions
 * {
 *     use AsFactory;
 *
 *     protected bool $notifyCustomer = true;
 *     protected bool $restockItems = true;
 *     protected ?string $reason = null;
 *
 *     public function handle(Order $order): Refund
 *     {
 *         $refund = Refund::create([
 *             'order_id' => $order->id,
 *             'reason' => $this->reason,
 *         ]);
 *
 *         if ($this->notifyCustomer) {
 *             // Send notification
 *         }
 *
 *         if ($this->restockItems) {
 *             // Restock items
 *         }
 *
 *         return $refund;
 *     }
 *
 *     public static function silent(): self
 *     {
 *         return static::make()->setNotifyCustomer(false);
 *     }
 *
 *     public static function withoutRestock(): self
 *     {
 *         return static::make()->setRestockItems(false);
 *     }
 *
 *     public static function withReason(string $reason): self
 *     {
 *         return static::make()->setReason($reason);
 *     }
 *
 *     public function setNotifyCustomer(bool $notify): self
 *     {
 *         $this->notifyCustomer = $notify;
 *         return $this;
 *     }
 *
 *     public function setRestockItems(bool $restock): self
 *     {
 *         $this->restockItems = $restock;
 *         return $this;
 *     }
 *
 *     public function setReason(string $reason): self
 *     {
 *         $this->reason = $reason;
 *         return $this;
 *     }
 * }
 *
 * // Usage:
 * ProcessRefund::silent()
 *     ->withoutRestock()
 *     ->withReason('Customer request')
 *     ->handle($order);
 * @example
 * // ============================================
 * // Example 9: Factory with AsHydratable
 * // ============================================
 * class CreateUser extends Actions
 * {
 *     use AsFactory, AsHydratable;
 *
 *     protected array $fillable = ['name', 'email', 'password'];
 *
 *     public function handle(): User
 *     {
 *         return User::create([
 *             'name' => $this->name,
 *             'email' => $this->email,
 *             'password' => $this->password,
 *         ]);
 *     }
 *
 *     public static function withData(array $data): self
 *     {
 *         return static::make()->fill($data);
 *     }
 * }
 *
 * // Usage:
 * $user = CreateUser::withData([
 *     'name' => 'John Doe',
 *     'email' => 'john@example.com',
 *     'password' => 'secret',
 * ])->handle();
 * @example
 * // ============================================
 * // Example 10: Factory with Validation
 * // ============================================
 * class UpdateProfile extends Actions
 * {
 *     use AsFactory;
 *
 *     protected ?User $user = null;
 *     protected array $data = [];
 *
 *     public function handle(): User
 *     {
 *         $this->validate();
 *         return $this->user->update($this->data);
 *     }
 *
 *     public static function forUser(User $user): self
 *     {
 *         return static::make()->setUser($user);
 *     }
 *
 *     public function withData(array $data): self
 *     {
 *         $this->data = $data;
 *         return $this;
 *     }
 *
 *     public function setUser(User $user): self
 *     {
 *         $this->user = $user;
 *         return $this;
 *     }
 *
 *     protected function validate(): void
 *     {
 *         // Validation logic
 *     }
 * }
 *
 * // Usage:
 * UpdateProfile::forUser($user)
 *     ->withData(['name' => 'New Name'])
 *     ->handle();
 * @example
 * // ============================================
 * // Example 11: Factory with Multiple Return Types
 * // ============================================
 * class ProcessData extends Actions
 * {
 *     use AsFactory;
 *
 *     protected string $outputFormat = 'json';
 *
 *     public function handle(array $data): string|array
 *     {
 *         return match($this->outputFormat) {
 *             'json' => json_encode($data),
 *             'array' => $data,
 *             'xml' => $this->toXml($data),
 *             default => $data,
 *         };
 *     }
 *
 *     public static function asJson(): self
 *     {
 *         return static::make()->setOutputFormat('json');
 *     }
 *
 *     public static function asArray(): self
 *     {
 *         return static::make()->setOutputFormat('array');
 *     }
 *
 *     public static function asXml(): self
 *     {
 *         return static::make()->setOutputFormat('xml');
 *     }
 *
 *     public function setOutputFormat(string $format): self
 *     {
 *         $this->outputFormat = $format;
 *         return $this;
 *     }
 *
 *     protected function toXml(array $data): string
 *     {
 *         // Convert to XML
 *         return '';
 *     }
 * }
 *
 * // Usage:
 * $json = ProcessData::asJson()->handle($data);
 * $array = ProcessData::asArray()->handle($data);
 * @example
 * // ============================================
 * // Example 12: Factory with Builder Pattern
 * // ============================================
 * class BuildQuery extends Actions
 * {
 *     use AsFactory;
 *
 *     protected array $wheres = [];
 *     protected array $orders = [];
 *     protected ?int $limit = null;
 *
 *     public function handle(string $table): \Illuminate\Database\Query\Builder
 *     {
 *         $query = \DB::table($table);
 *
 *         foreach ($this->wheres as $where) {
 *             $query->where($where['column'], $where['operator'], $where['value']);
 *         }
 *
 *         foreach ($this->orders as $order) {
 *             $query->orderBy($order['column'], $order['direction']);
 *         }
 *
 *         if ($this->limit) {
 *             $query->limit($this->limit);
 *         }
 *
 *         return $query;
 *     }
 *
 *     public static function new(): self
 *     {
 *         return static::make();
 *     }
 *
 *     public function where(string $column, string $operator, $value): self
 *     {
 *         $this->wheres[] = compact('column', 'operator', 'value');
 *         return $this;
 *     }
 *
 *     public function orderBy(string $column, string $direction = 'asc'): self
 *     {
 *         $this->orders[] = compact('column', 'direction');
 *         return $this;
 *     }
 *
 *     public function limit(int $limit): self
 *     {
 *         $this->limit = $limit;
 *         return $this;
 *     }
 * }
 *
 * // Usage:
 * $query = BuildQuery::new()
 *     ->where('status', '=', 'active')
 *     ->orderBy('created_at', 'desc')
 *     ->limit(10)
 *     ->handle('users');
 * @example
 * // ============================================
 * // Example 13: Factory with State Management
 * // ============================================
 * class WorkflowAction extends Actions
 * {
 *     use AsFactory;
 *
 *     protected string $state = 'initial';
 *     protected array $context = [];
 *
 *     public function handle(): mixed
 *     {
 *         return match($this->state) {
 *             'initial' => $this->initialize(),
 *             'processing' => $this->process(),
 *             'completed' => $this->complete(),
 *             default => throw new \Exception('Invalid state'),
 *         };
 *     }
 *
 *     public static function initialState(): self
 *     {
 *         return static::make()->setState('initial');
 *     }
 *
 *     public static function processingState(): self
 *     {
 *         return static::make()->setState('processing');
 *     }
 *
 *     public static function withContext(array $context): self
 *     {
 *         return static::make()->setContext($context);
 *     }
 *
 *     public function setState(string $state): self
 *     {
 *         $this->state = $state;
 *         return $this;
 *     }
 *
 *     public function setContext(array $context): self
 *     {
 *         $this->context = $context;
 *         return $this;
 *     }
 *
 *     protected function initialize(): mixed
 *     {
 *         return 'initialized';
 *     }
 *
 *     protected function process(): mixed
 *     {
 *         return 'processed';
 *     }
 *
 *     protected function complete(): mixed
 *     {
 *         return 'completed';
 *     }
 * }
 *
 * // Usage:
 * $result = WorkflowAction::initialState()
 *     ->withContext(['key' => 'value'])
 *     ->handle();
 * @example
 * // ============================================
 * // Example 14: Factory with Event Dispatching
 * // ============================================
 * class PublishArticle extends Actions
 * {
 *     use AsFactory;
 *
 *     protected bool $dispatchEvents = true;
 *     protected ?Article $article = null;
 *
 *     public function handle(Article $article): Article
 *     {
 *         $this->article = $article;
 *         $article->publish();
 *
 *         if ($this->dispatchEvents) {
 *             event(new ArticlePublished($article));
 *         }
 *
 *         return $article;
 *     }
 *
 *     public static function silently(): self
 *     {
 *         return static::make()->setDispatchEvents(false);
 *     }
 *
 *     public static function withArticle(Article $article): self
 *     {
 *         return static::make()->setArticle($article);
 *     }
 *
 *     public function setDispatchEvents(bool $dispatch): self
 *     {
 *         $this->dispatchEvents = $dispatch;
 *         return $this;
 *     }
 *
 *     public function setArticle(Article $article): self
 *     {
 *         $this->article = $article;
 *         return $this;
 *     }
 * }
 *
 * // Usage:
 * PublishArticle::silently()->handle($article);
 * PublishArticle::withArticle($article)->handle($article);
 * @example
 * // ============================================
 * // Example 15: Factory with Caching
 * // ============================================
 * class FetchData extends Actions
 * {
 *     use AsFactory;
 *
 *     protected bool $useCache = true;
 *     protected ?int $cacheTtl = 3600;
 *
 *     public function handle(string $key): mixed
 *     {
 *         if ($this->useCache && \Cache::has($key)) {
 *             return \Cache::get($key);
 *         }
 *
 *         $data = $this->fetchFromSource($key);
 *
 *         if ($this->useCache) {
 *             \Cache::put($key, $data, $this->cacheTtl);
 *         }
 *
 *         return $data;
 *     }
 *
 *     public static function withoutCache(): self
 *     {
 *         return static::make()->setUseCache(false);
 *     }
 *
 *     public static function withCacheTtl(int $ttl): self
 *     {
 *         return static::make()->setCacheTtl($ttl);
 *     }
 *
 *     public function setUseCache(bool $useCache): self
 *     {
 *         $this->useCache = $useCache;
 *         return $this;
 *     }
 *
 *     public function setCacheTtl(int $ttl): self
 *     {
 *         $this->cacheTtl = $ttl;
 *         return $this;
 *     }
 *
 *     protected function fetchFromSource(string $key): mixed
 *     {
 *         // Fetch from source
 *         return [];
 *     }
 * }
 *
 * // Usage:
 * $data = FetchData::withoutCache()->handle('key');
 * $data = FetchData::withCacheTtl(7200)->handle('key');
 * @example
 * // ============================================
 * // Example 16: Factory with Retry Logic
 * // ============================================
 * class RetryableAction extends Actions
 * {
 *     use AsFactory;
 *
 *     protected int $maxAttempts = 3;
 *     protected int $delay = 1000;
 *
 *     public function handle(): mixed
 *     {
 *         $attempts = 0;
 *
 *         while ($attempts < $this->maxAttempts) {
 *             try {
 *                 return $this->execute();
 *             } catch (\Exception $e) {
 *                 $attempts++;
 *                 if ($attempts >= $this->maxAttempts) {
 *                     throw $e;
 *                 }
 *                 usleep($this->delay * 1000);
 *             }
 *         }
 *     }
 *
 *     public static function withMaxAttempts(int $attempts): self
 *     {
 *         return static::make()->setMaxAttempts($attempts);
 *     }
 *
 *     public static function withDelay(int $milliseconds): self
 *     {
 *         return static::make()->setDelay($milliseconds);
 *     }
 *
 *     public function setMaxAttempts(int $attempts): self
 *     {
 *         $this->maxAttempts = $attempts;
 *         return $this;
 *     }
 *
 *     public function setDelay(int $milliseconds): self
 *     {
 *         $this->delay = $milliseconds;
 *         return $this;
 *     }
 *
 *     protected function execute(): mixed
 *     {
 *         // Action logic
 *         return 'result';
 *     }
 * }
 *
 * // Usage:
 * RetryableAction::withMaxAttempts(5)
 *     ->withDelay(2000)
 *     ->handle();
 * @example
 * // ============================================
 * // Example 17: Factory with Multiple Actions
 * // ============================================
 * class BatchProcess extends Actions
 * {
 *     use AsFactory;
 *
 *     protected array $actions = [];
 *     protected bool $stopOnError = false;
 *
 *     public function handle(): array
 *     {
 *         $results = [];
 *
 *         foreach ($this->actions as $action) {
 *             try {
 *                 $results[] = $action->handle();
 *             } catch (\Exception $e) {
 *                 if ($this->stopOnError) {
 *                     throw $e;
 *                 }
 *                 $results[] = ['error' => $e->getMessage()];
 *             }
 *         }
 *
 *         return $results;
 *     }
 *
 *     public static function withActions(array $actions): self
 *     {
 *         return static::make()->setActions($actions);
 *     }
 *
 *     public static function stopOnError(): self
 *     {
 *         return static::make()->setStopOnError(true);
 *     }
 *
 *     public function setActions(array $actions): self
 *     {
 *         $this->actions = $actions;
 *         return $this;
 *     }
 *
 *     public function setStopOnError(bool $stop): self
 *     {
 *         $this->stopOnError = $stop;
 *         return $this;
 *     }
 * }
 *
 * // Usage:
 * BatchProcess::withActions([$action1, $action2, $action3])
 *     ->stopOnError()
 *     ->handle();
 * @example
 * // ============================================
 * // Example 18: Factory with Transformation
 * // ============================================
 * class TransformData extends Actions
 * {
 *     use AsFactory;
 *
 *     protected array $transformers = [];
 *
 *     public function handle(array $data): array
 *     {
 *         foreach ($this->transformers as $transformer) {
 *             $data = $transformer($data);
 *         }
 *
 *         return $data;
 *     }
 *
 *     public static function withTransformer(callable $transformer): self
 *     {
 *         return static::make()->addTransformer($transformer);
 *     }
 *
 *     public function addTransformer(callable $transformer): self
 *     {
 *         $this->transformers[] = $transformer;
 *         return $this;
 *     }
 * }
 *
 * // Usage:
 * TransformData::withTransformer(fn($data) => array_map('strtoupper', $data))
 *     ->addTransformer(fn($data) => array_filter($data))
 *     ->handle(['a', 'b', 'c']);
 * @example
 * // ============================================
 * // Example 19: Factory with Logging
 * // ============================================
 * class LoggedAction extends Actions
 * {
 *     use AsFactory, AsLogger;
 *
 *     protected bool $enableLogging = true;
 *     protected string $logLevel = 'info';
 *
 *     public function handle(string $message): void
 *     {
 *         if ($this->enableLogging) {
 *             \Log::{$this->logLevel}($message);
 *         }
 *     }
 *
 *     public static function withoutLogging(): self
 *     {
 *         return static::make()->setEnableLogging(false);
 *     }
 *
 *     public static function withLogLevel(string $level): self
 *     {
 *         return static::make()->setLogLevel($level);
 *     }
 *
 *     public function setEnableLogging(bool $enable): self
 *     {
 *         $this->enableLogging = $enable;
 *         return $this;
 *     }
 *
 *     public function setLogLevel(string $level): self
 *     {
 *         $this->logLevel = $level;
 *         return $this;
 *     }
 * }
 *
 * // Usage:
 * LoggedAction::withoutLogging()->handle('message');
 * LoggedAction::withLogLevel('error')->handle('message');
 * @example
 * // ============================================
 * // Example 20: Factory with Testing
 * // ============================================
 * class TestableAction extends Actions
 * {
 *     use AsFactory, AsFake;
 *
 *     protected bool $testMode = false;
 *
 *     public function handle(): mixed
 *     {
 *         if ($this->testMode) {
 *             return 'test result';
 *         }
 *
 *         return $this->realImplementation();
 *     }
 *
 *     public static function inTestMode(): self
 *     {
 *         return static::make()->setTestMode(true);
 *     }
 *
 *     public function setTestMode(bool $testMode): self
 *     {
 *         $this->testMode = $testMode;
 *         return $this;
 *     }
 *
 *     protected function realImplementation(): mixed
 *     {
 *         // Real implementation
 *         return 'real result';
 *     }
 * }
 *
 * // Usage in tests:
 * $result = TestableAction::inTestMode()->handle();
 * expect($result)->toBe('test result');
 */
trait AsFactory
{
    /**
     * Create a new instance with the given attributes.
     *
     * This method resolves the action from the service container and sets
     * any provided attributes as properties on the instance.
     *
     * Note: This method unwraps decorators to return the actual action instance,
     * as the container may wrap actions with decorators for cross-cutting concerns.
     *
     * @param  array<string, mixed>  $attributes
     * @return static
     */
    public static function make(array $attributes = []): self
    {
        $instance = app(static::class);

        // Unwrap decorators to get the actual action instance
        $instance = static::unwrapDecorator($instance);

        foreach ($attributes as $key => $value) {
            if (property_exists($instance, $key)) {
                $instance->{$key} = $value;
            }
        }

        return $instance;
    }

    /**
     * Unwrap decorators to get the original action instance.
     *
     * @param  mixed  $instance
     * @return mixed
     */
    protected static function unwrapDecorator($instance)
    {
        // If instance is a decorator, get the wrapped action
        if (str_starts_with(get_class($instance), 'App\\Actions\\Decorators\\')) {
            $reflection = new \ReflectionClass($instance);
            if ($reflection->hasProperty('action')) {
                $property = $reflection->getProperty('action');
                $property->setAccessible(true);
                $wrappedAction = $property->getValue($instance);

                return static::unwrapDecorator($wrappedAction);
            }
        }

        return $instance;
    }

    /**
     * Create a new instance and immediately call handle.
     *
     * This is a convenience method that combines make() and handle() in one call.
     *
     * @param  mixed  ...$arguments
     */
    public static function create(...$arguments): mixed
    {
        return static::make()->handle(...$arguments);
    }
}
