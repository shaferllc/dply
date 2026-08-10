<?php

/**
 * dply — DigitalOcean Functions ↔ Laravel adapter.
 *
 * Injected into a checked-out Laravel app at deploy time by
 * App\Modules\Deploy\Services\DigitalOceanFunctionsLaravelAdapter. It is the
 * OpenWhisk-side counterpart to bref/laravel-bridge: DigitalOcean Functions
 * invokes main($args); this file translates that raw web-action event into
 * an Illuminate HTTP request, runs it through Laravel's HTTP kernel, and
 * maps the response back to the {statusCode, headers, body} shape OpenWhisk
 * expects.
 *
 * The OpenWhisk action filesystem is read-only except for /tmp, so before
 * the framework boots this redirects Laravel's storage path AND every
 * bootstrap/cache file (config/events/packages/routes/services) into /tmp —
 * otherwise a cold boot that needs to (re)write any of them crashes.
 *
 * Do not edit in the user's repo — dply overwrites this file on every deploy.
 */

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Lock;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\Queue\Factory;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Monolog\Handler\StreamHandler;
use Symfony\Component\HttpFoundation\Response;

if (! function_exists('dply_do_functions_cors_headers')) {
    /**
     * Response headers for the CORS policy dply binds as a default parameter
     * when the operator takes CORS over from the platform.
     *
     * An origin outside the policy gets no CORS headers at all — that IS the
     * rejection; inventing a header would defeat the allow-list.
     *
     * @param  array<string, mixed>  $policy
     * @param  array<string, mixed>  $args
     * @return array<string, string>
     */
    function dply_do_functions_cors_headers(array $policy, array $args): array
    {
        $requestHeaders = is_array($args['__ow_headers'] ?? null) ? $args['__ow_headers'] : [];
        $origin = trim((string) ($requestHeaders['origin'] ?? $requestHeaders['Origin'] ?? ''));
        $allowed = is_array($policy['allow_origins'] ?? null) ? $policy['allow_origins'] : ['*'];
        $credentials = (bool) ($policy['allow_credentials'] ?? false);

        if (in_array('*', $allowed, true)) {
            // `*` cannot be combined with credentials, so echo the caller's
            // origin when credentials are in play.
            $allowOrigin = ($credentials && $origin !== '') ? $origin : '*';
        } elseif ($origin !== '' && in_array($origin, $allowed, true)) {
            $allowOrigin = $origin;
        } else {
            return [];
        }

        $headers = ['Access-Control-Allow-Origin' => $allowOrigin];
        if ($allowOrigin !== '*') {
            $headers['Vary'] = 'Origin';
        }
        foreach ([
            'allow_methods' => 'Access-Control-Allow-Methods',
            'allow_headers' => 'Access-Control-Allow-Headers',
            'expose_headers' => 'Access-Control-Expose-Headers',
        ] as $key => $header) {
            $values = is_array($policy[$key] ?? null) ? $policy[$key] : [];
            if ($values !== []) {
                $headers[$header] = implode(', ', array_map('strval', $values));
            }
        }
        if ($credentials) {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }
        if (($policy['max_age'] ?? null) !== null) {
            $headers['Access-Control-Max-Age'] = (string) $policy['max_age'];
        }

        return $headers;
    }
}

if (! function_exists('main')) {
    /**
     * @param  array<string, mixed>  $args
     * @return array{statusCode: int, headers: array<string, string>, body: string}
     */
    function main(array $args): array
    {
        $corsPolicy = is_array($args['__dply_cors'] ?? null) ? $args['__dply_cors'] : null;

        // With web-custom-options in force the platform stops answering
        // preflight, so the function has to. Answered before the framework
        // boots: a preflight carries no session and no route to resolve, and
        // a cold Laravel boot is the most expensive thing in this file.
        if ($corsPolicy !== null && strtoupper((string) ($args['__ow_method'] ?? 'GET')) === 'OPTIONS') {
            return [
                'statusCode' => 204,
                'headers' => dply_do_functions_cors_headers($corsPolicy, $args),
                'body' => '',
            ];
        }

        $root = __DIR__;
        $storage = '/tmp/dply-storage';
        $bootstrapCache = $storage.'/bootstrap';

        foreach ([
            $storage.'/framework/views',
            $storage.'/framework/cache/data',
            $storage.'/framework/sessions',
            $storage.'/logs',
            $storage.'/app',
            $bootstrapCache,
        ] as $dir) {
            if (! is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
        }

        // Parse the bundled .env once so the defaults below can yield to it.
        $envFile = [];
        if (is_file($root.'/.env')) {
            foreach (file($root.'/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)$/', (string) $line, $m) === 1) {
                    $envFile[$m[1]] = trim($m[2], "\"' ");
                }
            }
        }

        $setEnv = static function (string $key, string $value): void {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        };

        // Read-only-filesystem redirects — bootstrap/cache and the compiled
        // view path MUST point at /tmp here, so these always win.
        foreach ([
            'VIEW_COMPILED_PATH' => $storage.'/framework/views',
            'APP_CONFIG_CACHE' => $bootstrapCache.'/config.php',
            'APP_EVENTS_CACHE' => $bootstrapCache.'/events.php',
            'APP_PACKAGES_CACHE' => $bootstrapCache.'/packages.php',
            'APP_ROUTES_CACHE' => $bootstrapCache.'/routes.php',
            'APP_SERVICES_CACHE' => $bootstrapCache.'/services.php',
        ] as $key => $value) {
            $setEnv($key, $value);
        }

        // Serverless-safe driver defaults — applied ONLY when the app's .env
        // does not set them, so provisioning Redis (CACHE_STORE=redis) or a
        // database queue stays in the operator's control.
        foreach ([
            'APP_ENV' => 'production',
            'LOG_CHANNEL' => 'stderr',
            'CACHE_STORE' => 'array',
            'CACHE_DRIVER' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
        ] as $key => $value) {
            if (($envFile[$key] ?? '') === '') {
                $setEnv($key, $value);
            }
        }

        // Laravel needs an APP_KEY to build the encrypter (the cookie
        // middleware resolves it on terminate). If the deployed app ships no
        // key, mint an ephemeral one so it can boot. A real app should set a
        // stable APP_KEY — a per-cold-start key cannot decrypt data written
        // by another instance.
        if (($envFile['APP_KEY'] ?? '') === '' && trim((string) (getenv('APP_KEY') ?: '')) === '') {
            $setEnv('APP_KEY', 'base64:'.base64_encode(random_bytes(32)));
        }

        try {
            require $root.'/vendor/autoload.php';

            /** @var Application $app */
            $app = require $root.'/bootstrap/app.php';
            $app->useStoragePath($storage);

            // Register the dply Queue connection, the shared lock store, and
            // the failed-job provider, if this function has a namespace.
            dply_do_functions_register_queue($app, $envFile);
            dply_do_functions_register_queue_locks($app, $envFile);
            dply_do_functions_register_failed_jobs($app, $envFile);

            // dply background tick — run the Laravel scheduler or a queue
            // worker instead of handling an HTTP request. The scheduler runs
            // on a one-minute cron; queue work is driven by dply's pump,
            // which holds several of these invocations open at once.
            $task = dply_do_functions_command($args, $envFile);
            if ($task !== null) {
                $consoleKernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

                // A queue slot answers in JSON: what it drained, and what is
                // still waiting. The pump scales its concurrency from
                // `remaining` and re-invokes immediately while work is left,
                // so queue latency is not tied to a cron interval.
                if ($task[0] === 'queue:work') {
                    return dply_do_functions_queue_slot($app, $consoleKernel, $task);
                }

                $exitCode = $consoleKernel->call($task[0], $task[1]);

                return [
                    'statusCode' => $exitCode === 0 ? 200 : 500,
                    'headers' => ['content-type' => 'text/plain; charset=utf-8'],
                    'body' => 'dply ran '.$task[0].' — exit '.$exitCode."\n\n".$consoleKernel->output(),
                ];
            }

            $request = dply_do_functions_request($args);

            // Capture this request's Laravel log records — DigitalOcean
            // Functions never persists them, so the visit report below is
            // dply's only window into what the app logged while serving.
            $drainLogs = dply_do_functions_attach_log_capture($app);

            // Watch for jobs queued while serving this request. If any are,
            // ping dply's pump on the way out so draining starts now rather
            // than at the next one-minute tick.
            $flushQueueWake = dply_do_functions_attach_queue_wake($app, $envFile);

            /** @var Kernel $kernel */
            $kernel = $app->make(Kernel::class);
            $startedAt = microtime(true);
            $response = $kernel->handle($request);
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $body = $response->getContent();
            $kernel->terminate($request, $response);

            $headers = [];
            foreach ($response->headers->allPreserveCaseWithoutCookies() as $name => $values) {
                $headers[$name] = implode(', ', array_map('strval', (array) $values));
            }

            // The app's own headers win — a route (or a CORS middleware the
            // app already runs) that sets its own header has made a
            // deliberate choice the policy shouldn't overwrite.
            if ($corsPolicy !== null) {
                $headers += dply_do_functions_cors_headers($corsPolicy, $args);
            }

            // Report this request to dply's ingest endpoint. Skipped for
            // dply-initiated invocations (ticks / the Logs-page test button)
            // — dply already captures those inline.
            dply_do_functions_report_visit(
                $args, $envFile, $request, $response, $durationMs, $drainLogs
            );

            // After the response is built, so the ping never sits in the
            // user's request path any longer than the fire-and-forget POST.
            $flushQueueWake();

            return [
                'statusCode' => $response->getStatusCode(),
                'headers' => $headers,
                'body' => is_string($body) ? $body : '',
            ];
        } catch (Throwable $e) {
            // OpenWhisk would otherwise swallow this behind a generic
            // "error processing your request" — surface the real cause.
            fwrite(STDERR, 'dply adapter error: '.$e.PHP_EOL);

            return [
                'statusCode' => 500,
                'headers' => ['content-type' => 'application/json'],
                'body' => (string) json_encode([
                    'error' => 'The Laravel app failed to handle this request on DigitalOcean Functions.',
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                    'at' => $e->getFile().':'.$e->getLine(),
                    'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 20),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ];
        }
    }
}

if (! function_exists('dply_do_functions_command')) {
    /**
     * Detect a dply background tick. Returns the artisan command + parameters
     * to run, or null for a normal HTTP request. A wrong/missing secret
     * throws — a tick must never silently fall through to serving the app.
     *
     * @param  array<string, mixed>  $args
     * @param  array<string, string>  $envFile  parsed bundled .env
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    function dply_do_functions_command(array $args, array $envFile = []): ?array
    {
        $headers = is_array($args['__ow_headers'] ?? null) ? $args['__ow_headers'] : [];
        $task = strtolower(trim((string) ($headers['x-dply-run'] ?? '')));
        if ($task === '') {
            return null;
        }

        // The command secret is delivered in the bundled .env — DigitalOcean
        // Functions does not promote .env keys to real environment variables,
        // so resolve it .env-first (mirroring dply_do_functions_report_visit)
        // and fall back to a real env var only if one is set.
        $secret = trim((string) ($envFile['DPLY_COMMAND_SECRET'] ?? (getenv('DPLY_COMMAND_SECRET') ?: '')));
        $given = trim((string) ($headers['x-dply-secret'] ?? ''));
        if ($secret === '' || ! hash_equals($secret, $given)) {
            throw new RuntimeException('dply command rejected: invalid command secret.');
        }

        if ($task === 'schedule') {
            return ['schedule:run', []];
        }

        if ($task === 'queue') {
            // The pump tunes each slot: how long one invocation may drain,
            // how many jobs it may take, and which queue to read. The
            // defaults reproduce the pre-pump once-a-minute tick, so an old
            // caller against a freshly deployed handler behaves unchanged.
            $maxTime = (int) ($headers['x-dply-queue-max-time'] ?? 50);
            $maxJobs = (int) ($headers['x-dply-queue-max-jobs'] ?? 0);
            $queue = trim((string) ($headers['x-dply-queue'] ?? ''));

            $options = [
                '--stop-when-empty' => true,
                // Never let a slot outlive the platform's invocation timeout:
                // a worker killed mid-job leaves the job reserved until its
                // visibility timeout expires, which stalls that queue.
                '--max-time' => max(1, min(880, $maxTime)),
            ];

            if ($maxJobs > 0) {
                $options['--max-jobs'] = $maxJobs;
            }

            if ($queue !== '') {
                $options['--queue'] = $queue;
            }

            return ['queue:work', $options];
        }

        if ($task === 'queue-retry') {
            // Push failed jobs back onto the queue. `all`, or a specific
            // Laravel failed-job uuid — the same handle the slot reported
            // when the failure was captured. The pump wakes right after, so
            // a retried job starts draining immediately.
            $id = trim((string) ($headers['x-dply-queue-retry-id'] ?? 'all'));

            // Must start alphanumeric: a leading dash would be option-shaped.
            // Symfony passes this as an argument value rather than re-parsing
            // it, so `--force` was never actually reachable as an option —
            // but an id that cannot look like one is one less thing to reason
            // about every time this command grows a flag.
            if ($id !== 'all' && preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,63}$/', $id) !== 1) {
                throw new RuntimeException('dply command rejected: malformed retry id.');
            }

            return ['queue:retry', ['id' => [$id]]];
        }

        throw new RuntimeException('dply command rejected: unknown task "'.$task.'".');
    }
}

if (! function_exists('dply_do_functions_register_queue')) {
    /**
     * Define the `dply` queue connection from the injected DPLY_QUEUE_* env.
     *
     * dply Queue speaks the SQS wire protocol, so this is Laravel's own `sqs`
     * driver pointed at us — no package, no custom driver, nothing for the
     * customer to install.
     *
     * Registered here rather than by asking the app to edit config/queue.php,
     * for two reasons found the hard way:
     *
     *  1. A stock Laravel `sqs` connection has no `endpoint` key, and without
     *     one the AWS SDK routes to real AWS no matter what SQS_PREFIX says.
     *  2. That connection reads AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY.
     *     Repurposing those for the queue would hand the app's S3 credentials
     *     to dply — so this defines a SEPARATE connection and leaves the
     *     app's AWS config untouched.
     *
     * No-ops when the env is absent, so a function without a queue namespace
     * behaves exactly as before.
     *
     * @param  array<string, string>  $envFile  parsed bundled .env
     */
    function dply_do_functions_register_queue(mixed $app, array $envFile): void
    {
        $resolve = static fn (string $key): string => trim(
            (string) ($envFile[$key] ?? (getenv($key) ?: ''))
        );

        $url = $resolve('DPLY_QUEUE_URL');
        $key = $resolve('DPLY_QUEUE_KEY');
        $secret = $resolve('DPLY_QUEUE_SECRET');

        if ($url === '' || $key === '' || $secret === '') {
            return;
        }

        try {
            $config = $app->make('config');

            $config->set('queue.connections.dply', [
                'driver' => 'sqs',
                'key' => $key,
                'secret' => $secret,
                // Laravel builds the queue URL as prefix/queue, and the SDK
                // needs `endpoint` to actually send it here.
                'prefix' => rtrim($url, '/'),
                'endpoint' => rtrim($url, '/'),
                'queue' => $resolve('DPLY_QUEUE_DEFAULT') !== '' ? $resolve('DPLY_QUEUE_DEFAULT') : 'default',
                'suffix' => '',
                'region' => $resolve('DPLY_QUEUE_REGION') !== '' ? $resolve('DPLY_QUEUE_REGION') : 'us-east-1',
                'after_commit' => false,
            ]);
        } catch (Throwable) {
            // A queue that cannot be registered must not stop the app from
            // serving HTTP. The backend classifier and queue-doctor surface
            // the misconfiguration instead.
        }
    }
}

if (! function_exists('dply_queue_http')) {
    /**
     * One blocking JSON call to a dply-native queue endpoint.
     *
     * Bearer-authenticated: these are not SQS operations, so there is nothing
     * to sign and no signer to ship. Blocking, unlike the fire-and-forget log
     * and wake calls — a lock answer the caller ignores is not a lock.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null null on any transport failure
     */
    function dply_queue_http(string $base, string $secret, string $method, string $path, array $payload = []): ?array
    {
        $url = rtrim($base, '/').$path;

        try {
            $ch = curl_init($url);
            if ($ch === false) {
                return null;
            }

            $body = (string) json_encode($payload);

            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_POSTFIELDS => $method === 'GET' ? null : $body,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Authorization: Bearer '.$secret,
                ],
                CURLOPT_TIMEOUT_MS => 3000,
                CURLOPT_CONNECTTIMEOUT_MS => 1000,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_NOSIGNAL => true,
            ]);

            $response = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if (! is_string($response) || $status >= 400) {
                return null;
            }

            $decoded = json_decode($response, true);

            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            return null;
        }
    }
}

if (! function_exists('dply_do_functions_register_queue_locks')) {
    /**
     * Register a cache store whose locks are shared across invocations.
     *
     * `ShouldBeUnique`, `WithoutOverlapping`, and `RateLimited` are backed by
     * Laravel's CACHE, not its queue. On a function the cache store defaults
     * to `array` — per-invocation — so all three silently do nothing: every
     * lock is granted, every duplicate runs, and nothing errors or logs. That
     * is a worse failure than the queue one, because it looks like it works.
     *
     * This registers a `dply` cache driver whose lock methods talk to the
     * namespace's shared lock table. Only the lock half is backed; ordinary
     * cache get/put stay in-memory, because a general managed cache is a
     * different product. Laravel only needs `LockProvider` for the three
     * features above.
     *
     * @param  array<string, string>  $envFile
     */
    function dply_do_functions_register_queue_locks(mixed $app, array $envFile): void
    {
        $resolve = static fn (string $key): string => trim(
            (string) ($envFile[$key] ?? (getenv($key) ?: ''))
        );

        $base = $resolve('DPLY_QUEUE_URL');
        $secret = $resolve('DPLY_QUEUE_SECRET');

        if ($base === '' || $secret === '') {
            return;
        }

        try {
            $manager = $app->make('cache');

            $manager->extend('dply', function ($container) use ($base, $secret) {
                $store = new class($base, $secret) extends ArrayStore
                {
                    public function __construct(private string $base, private string $secret)
                    {
                        parent::__construct();
                    }

                    public function lock($name, $seconds = 0, $owner = null)
                    {
                        return new class($this->base, $this->secret, (string) $name, (int) $seconds, $owner) extends Lock
                        {
                            public function __construct(
                                private string $base,
                                private string $secret,
                                string $name,
                                int $seconds,
                                $owner = null,
                            ) {
                                parent::__construct($name, $seconds, $owner);
                            }

                            public function acquire()
                            {
                                $result = dply_queue_http($this->base, $this->secret, 'POST', '/locks/acquire', [
                                    'name' => $this->name,
                                    'owner' => $this->owner,
                                    // A zero-second lock means "until
                                    // released"; give the server a real TTL so
                                    // a crashed holder cannot wedge the queue.
                                    'seconds' => $this->seconds > 0 ? $this->seconds : 300,
                                ]);

                                // Unreachable dply must NOT grant the lock.
                                // Failing closed means a job is skipped;
                                // failing open means it runs twice, which is
                                // exactly what the caller asked to prevent.
                                return (bool) ($result['acquired'] ?? false);
                            }

                            public function release()
                            {
                                $result = dply_queue_http($this->base, $this->secret, 'POST', '/locks/release', [
                                    'name' => $this->name,
                                    'owner' => $this->owner,
                                ]);

                                return (bool) ($result['released'] ?? false);
                            }

                            public function forceRelease()
                            {
                                dply_queue_http($this->base, $this->secret, 'POST', '/locks/force-release', [
                                    'name' => $this->name,
                                ]);
                            }

                            protected function getCurrentOwner()
                            {
                                $result = dply_queue_http($this->base, $this->secret, 'POST', '/locks/owner', [
                                    'name' => $this->name,
                                ]);

                                return $result['owner'] ?? null;
                            }
                        };
                    }
                };

                return new Repository($store);
            });

            // Only take over the cache when the app has not chosen a shared
            // store of its own. An app already on Redis has a working lock
            // provider and should keep it.
            $current = (string) $app->make('config')->get('cache.default', 'array');

            if (in_array($current, ['array', 'file', ''], true)) {
                $app->make('config')->set('cache.stores.dply', ['driver' => 'dply']);
                $app->make('config')->set('cache.default', 'dply');
            }
        } catch (Throwable) {
            // A lock store that cannot be registered must not stop the app
            // serving HTTP; the queue-doctor reports the gap instead.
        }
    }
}

if (! function_exists('dply_do_functions_register_failed_jobs')) {
    /**
     * Record failed jobs in dply instead of the app's own database.
     *
     * Laravel's default provider writes to `failed_jobs` in the app database.
     * On a function backed by SQLite that is a per-container `/tmp` file, so a
     * job that exhausts its attempts is written and then disappears with the
     * container — nothing to inspect, nothing to retry.
     *
     * @param  array<string, string>  $envFile
     */
    function dply_do_functions_register_failed_jobs(mixed $app, array $envFile): void
    {
        $resolve = static fn (string $key): string => trim(
            (string) ($envFile[$key] ?? (getenv($key) ?: ''))
        );

        $base = $resolve('DPLY_QUEUE_URL');
        $secret = $resolve('DPLY_QUEUE_SECRET');

        if ($base === '' || $secret === '') {
            return;
        }

        try {
            $app->singleton('queue.failer', fn () => new class($base, $secret) implements FailedJobProviderInterface
            {
                public function __construct(private string $base, private string $secret) {}

                public function log($connection, $queue, $payload, $exception)
                {
                    $decoded = json_decode((string) $payload, true);

                    dply_queue_http($this->base, $this->secret, 'POST', '/failed-jobs', [
                        'uuid' => is_array($decoded) ? ($decoded['uuid'] ?? null) : null,
                        'queue' => $queue,
                        'payload' => $payload,
                        'exception' => (string) $exception,
                        'attempts' => is_array($decoded) ? ($decoded['attempts'] ?? 0) : 0,
                    ]);

                    return null;
                }

                public function all()
                {
                    $result = dply_queue_http($this->base, $this->secret, 'GET', '/failed-jobs');

                    return array_map(
                        static fn (array $row): object => (object) $row,
                        $result['failed_jobs'] ?? [],
                    );
                }

                public function find($id)
                {
                    $result = dply_queue_http($this->base, $this->secret, 'GET', '/failed-jobs/'.rawurlencode((string) $id));

                    return isset($result['failed_job']) ? (object) $result['failed_job'] : null;
                }

                public function forget($id)
                {
                    $result = dply_queue_http($this->base, $this->secret, 'DELETE', '/failed-jobs/'.rawurlencode((string) $id));

                    return (bool) ($result['forgotten'] ?? false);
                }

                public function flush($hours = null)
                {
                    dply_queue_http($this->base, $this->secret, 'POST', '/failed-jobs/flush', [
                        'hours' => $hours,
                    ]);
                }

                public function prune(DateTimeInterface $before)
                {
                    $hours = max(1, (int) ceil((time() - $before->getTimestamp()) / 3600));

                    $result = dply_queue_http($this->base, $this->secret, 'POST', '/failed-jobs/flush', [
                        'hours' => $hours,
                    ]);

                    return (int) ($result['flushed'] ?? 0);
                }
            });
        } catch (Throwable) {
            // Same reasoning as the lock store: never break serving.
        }
    }
}

if (! function_exists('dply_do_functions_attach_queue_wake')) {
    /**
     * Watch for jobs queued during this invocation and, if any were, tell
     * dply's pump to start draining.
     *
     * This is what makes serverless queue latency a round-trip instead of a
     * cron interval. It lives in the injected adapter rather than in a
     * composer package the user installs: dply already owns this entry file
     * and rewrites it on every deploy, so there is no version skew between
     * platform and package, and nothing for the user to install or keep
     * current.
     *
     * Debounced to one ping per invocation — a request that queues fifty
     * jobs still only wakes the pump once, and the pump's own `remaining`
     * feedback covers the rest.
     *
     * @param  array<string, string>  $envFile  parsed bundled .env
     * @return callable():void flush — safe to call exactly once, on the way out
     */
    function dply_do_functions_attach_queue_wake(mixed $app, array $envFile): callable
    {
        $queued = false;

        try {
            $app->make(Dispatcher::class)->listen(
                JobQueued::class,
                function () use (&$queued): void {
                    $queued = true;
                },
            );
        } catch (Throwable) {
            // No event dispatcher — nothing to watch. The safety-net tick
            // still drains this app, just at the old latency.
            return static function (): void {};
        }

        return static function () use (&$queued, $envFile): void {
            if (! $queued) {
                return;
            }

            $resolve = static fn (string $key): string => trim(
                (string) ($envFile[$key] ?? (getenv($key) ?: ''))
            );

            $url = $resolve('DPLY_QUEUE_WAKE_URL');
            $secret = $resolve('DPLY_COMMAND_SECRET');
            if ($url === '' || $secret === '') {
                return;
            }

            $host = (string) parse_url($url, PHP_URL_HOST);
            if ($host === '' || $host === 'localhost' || $host === '127.0.0.1' || str_ends_with($host, '.local')) {
                return;
            }

            try {
                $ch = curl_init($url);
                if ($ch === false) {
                    return;
                }
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => '{}',
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        'Accept: application/json',
                        'X-Dply-Secret: '.$secret,
                    ],
                    // Tighter than the log-ingest budget: a slow dply must
                    // never hold up the user's response. Missing a wake only
                    // costs latency — the safety-net tick still drains.
                    CURLOPT_TIMEOUT_MS => 500,
                    CURLOPT_CONNECTTIMEOUT_MS => 300,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_NOSIGNAL => true,
                ]);
                curl_exec($ch);
                curl_close($ch);
            } catch (Throwable) {
                // Fire-and-forget — swallow everything.
            }
        };
    }
}

if (! function_exists('dply_do_functions_queue_slot')) {
    /**
     * Run one queue slot and report the outcome to dply's pump.
     *
     * Counts come from the queue's own events rather than by parsing worker
     * output, and `remaining` is read from the queue driver after the drain
     * so the pump can decide whether to go again. A driver that cannot count
     * reports null instead of guessing — the pump treats that as "assume
     * more" and re-invokes, which is the safe direction to be wrong in.
     *
     * @param  array{0: string, 1: array<string, mixed>}  $task
     * @return array<string, mixed>
     */
    function dply_do_functions_queue_slot(mixed $app, mixed $consoleKernel, array $task): array
    {
        $processed = 0;
        $failed = 0;

        // A function has no worker process and no CLI, so `queue:failed` can
        // never be run against it from outside. Failures have to be reported
        // outward while the slot is running or they are invisible to the
        // operator — dply mirrors these into serverless_failed_jobs.
        $failures = [];

        try {
            $events = $app->make(Dispatcher::class);
            $events->listen(JobProcessed::class, function () use (&$processed): void {
                $processed++;
            });
            $events->listen(JobFailed::class, function (JobFailed $event) use (&$failed, &$failures): void {
                $failed++;

                // Bounded: one pathological slot must not return a megabyte
                // of stack traces. The count above stays exact regardless.
                if (count($failures) >= 20) {
                    return;
                }

                try {
                    $failures[] = [
                        'uuid' => $event->job->uuid(),
                        'connection_name' => $event->connectionName,
                        'queue' => $event->job->getQueue(),
                        'job_class' => $event->job->resolveName(),
                        'exception_message' => mb_substr((string) $event->exception->getMessage(), 0, 2000),
                        'exception_excerpt' => mb_substr((string) $event->exception, 0, 4000),
                        'failed_at' => gmdate('c'),
                    ];
                } catch (Throwable) {
                    // Never let failure *reporting* break failure *handling*.
                }
            });
        } catch (Throwable) {
            // Counting is best-effort; the drain itself still has to run.
        }

        $startedAt = microtime(true);
        $exitCode = $consoleKernel->call($task[0], $task[1]);
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        $queue = isset($task[1]['--queue']) ? (string) $task[1]['--queue'] : '';
        $remaining = dply_do_functions_queue_size($app, $queue);

        return [
            'statusCode' => $exitCode === 0 ? 200 : 500,
            'headers' => ['content-type' => 'application/json; charset=utf-8'],
            'body' => (string) json_encode([
                'dply_queue_slot' => true,
                'ok' => $exitCode === 0,
                'processed' => $processed,
                'failed' => $failed,
                'failures' => $failures,
                'remaining' => $remaining,
                'duration_ms' => $durationMs,
                'exit_code' => $exitCode,
            ]),
        ];
    }
}

if (! function_exists('dply_do_functions_queue_size')) {
    /**
     * Depth of the queue this slot drained, or null when the driver cannot
     * answer. `--queue` may be a comma-separated priority list; only the
     * first is counted, which is the one the pump cares about draining.
     */
    function dply_do_functions_queue_size(mixed $app, string $queue): ?int
    {
        try {
            $name = trim(explode(',', $queue)[0] ?? '');
            $connection = $app->make(Factory::class)->connection();

            return (int) $connection->size($name !== '' ? $name : null);
        } catch (Throwable) {
            return null;
        }
    }
}

if (! function_exists('dply_do_functions_request')) {
    /**
     * Rebuild an Illuminate request from an OpenWhisk raw web-action event.
     *
     * @param  array<string, mixed>  $args
     */
    function dply_do_functions_request(array $args): Request
    {
        $method = strtoupper((string) ($args['__ow_method'] ?? 'GET'));
        $path = '/'.ltrim((string) ($args['__ow_path'] ?? '/'), '/');
        $headers = is_array($args['__ow_headers'] ?? null) ? $args['__ow_headers'] : [];
        $queryString = (string) ($args['__ow_query'] ?? '');

        $body = (string) ($args['__ow_body'] ?? '');
        if (! empty($args['__ow_isBase64Encoded'])) {
            $body = (string) base64_decode($body, true);
        }

        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', (string) $name))] = $value;
        }
        $contentType = (string) ($headers['content-type'] ?? $headers['Content-Type'] ?? '');
        if ($contentType !== '') {
            $server['CONTENT_TYPE'] = $contentType;
        }

        $parameters = [];
        if ($body !== '' && ! in_array($method, ['GET', 'HEAD'], true)) {
            if (str_contains(strtolower($contentType), 'application/json')) {
                $decoded = json_decode($body, true);
                $parameters = is_array($decoded) ? $decoded : [];
            } elseif (str_contains(strtolower($contentType), 'application/x-www-form-urlencoded')) {
                parse_str($body, $parameters);
            }
        }

        $uri = $path.($queryString !== '' ? '?'.$queryString : '');

        return Request::createFromBase(
            Request::create($uri, $method, $parameters, [], [], $server, $body)
        );
    }
}

if (! function_exists('dply_do_functions_attach_log_capture')) {
    /**
     * Push an in-memory Monolog handler onto the app's default log channel
     * so this request's log records can be shipped to dply afterwards.
     *
     * Returns a drain callable: invoke it once after the request to get the
     * captured lines (a list of strings). Logging is strictly best-effort —
     * any failure here yields an empty drain and never touches the request.
     *
     * @return callable(): array<int, string>
     */
    function dply_do_functions_attach_log_capture(mixed $app): callable
    {
        try {
            $stream = fopen('php://temp', 'r+b');
            if ($stream === false) {
                return static fn (): array => [];
            }

            // Level 100 = DEBUG — an int so this works under Monolog 2 and 3.
            $handler = new StreamHandler($stream, 100);
            $app->make('log')->channel()->getLogger()->pushHandler($handler);

            return static function () use ($handler, $stream): array {
                try {
                    $handler->close();
                    rewind($stream);
                    $raw = rtrim((string) stream_get_contents($stream), "\n");
                    fclose($stream);

                    return $raw === '' ? [] : explode("\n", $raw);
                } catch (Throwable $e) {
                    return [];
                }
            };
        } catch (Throwable $e) {
            return static fn (): array => [];
        }
    }
}

if (! function_exists('dply_do_functions_report_visit')) {
    /**
     * Fire-and-forget POST one request's record to dply's ingest endpoint.
     *
     * DigitalOcean Functions never persists an activation for organic web
     * traffic, so this is dply's only record of it. It is best-effort: a
     * tight cURL timeout bounds the latency it adds, and any failure is
     * swallowed — a logging hiccup must never affect the user's request.
     *
     * No-ops when: the request was dply-initiated (a tick or the Logs-page
     * test button — dply captured it inline already); ingest isn't
     * configured; or the ingest host is local (the dev control plane isn't
     * reachable from DigitalOcean).
     *
     * @param  array<string, mixed>  $args
     * @param  array<string, string>  $envFile
     * @param  callable(): array<int, string>  $drainLogs
     */
    function dply_do_functions_report_visit(
        array $args,
        array $envFile,
        Request $request,
        Response $response,
        int $durationMs,
        callable $drainLogs,
    ): void {
        $logLines = $drainLogs();

        $headers = is_array($args['__ow_headers'] ?? null) ? $args['__ow_headers'] : [];
        foreach (['x-dply-run', 'x-dply-source'] as $marker) {
            if (trim((string) ($headers[$marker] ?? '')) !== '') {
                return;
            }
        }

        $resolve = static fn (string $key): string => trim(
            (string) ($envFile[$key] ?? (getenv($key) ?: ''))
        );
        $url = $resolve('DPLY_LOG_INGEST_URL');
        $secret = $resolve('DPLY_LOG_INGEST_SECRET');
        if ($url === '' || $secret === '') {
            return;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '' || $host === 'localhost' || $host === '127.0.0.1' || str_ends_with($host, '.local')) {
            return;
        }

        // Per-request detail for the Visits tab. The function sits behind
        // Cloudflare, so the real client IP + country arrive as cf-* headers.
        $reqHeaders = $request->headers;
        $body = $response->getContent();
        $context = array_filter([
            'ip' => $reqHeaders->get('cf-connecting-ip')
                ?: trim((string) explode(',', (string) $reqHeaders->get('x-forwarded-for'))[0]),
            'country' => $reqHeaders->get('cf-ipcountry'),
            'route' => $request->route()?->getName(),
            'query' => $request->getQueryString(),
            'content_type' => $response->headers->get('content-type'),
            'response_bytes' => is_string($body) ? strlen($body) : 0,
            'memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
            'php' => PHP_VERSION,
            'scheme' => $reqHeaders->get('x-forwarded-proto') ?: $request->getScheme(),
            'host' => $request->getHost(),
            'referer' => $reqHeaders->get('referer'),
            'user_agent' => $reqHeaders->get('user-agent'),
        ], static fn ($v): bool => $v !== null && $v !== '');

        $payload = json_encode([
            'method' => $request->getMethod(),
            'path' => $request->getPathInfo(),
            'status' => $response->getStatusCode(),
            'duration_ms' => $durationMs,
            'logs' => array_slice($logLines, -200),
            'context' => $context,
        ], JSON_UNESCAPED_SLASHES);
        if (! is_string($payload)) {
            return;
        }

        try {
            $ch = curl_init($url);
            if ($ch === false) {
                return;
            }
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'X-Dply-Signature: '.hash_hmac('sha256', $payload, $secret),
                ],
                CURLOPT_TIMEOUT_MS => 800,
                CURLOPT_CONNECTTIMEOUT_MS => 400,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_NOSIGNAL => true,
            ]);
            curl_exec($ch);
            curl_close($ch);
        } catch (Throwable $e) {
            // Fire-and-forget — swallow everything.
        }
    }
}
