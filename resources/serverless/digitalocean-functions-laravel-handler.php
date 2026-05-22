<?php

/**
 * dply — DigitalOcean Functions ↔ Laravel adapter.
 *
 * Injected into a checked-out Laravel app at deploy time by
 * App\Services\Deploy\DigitalOceanFunctionsLaravelAdapter. It is the
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

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Monolog\Handler\StreamHandler;
use Symfony\Component\HttpFoundation\Response;

if (! function_exists('main')) {
    /**
     * @param  array<string, mixed>  $args
     * @return array{statusCode: int, headers: array<string, string>, body: string}
     */
    function main(array $args): array
    {
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

            // dply background tick — run the Laravel scheduler or a queue
            // worker instead of handling an HTTP request. dply's own
            // scheduler invokes this every minute for enabled functions.
            $task = dply_do_functions_command($args, $envFile);
            if ($task !== null) {
                $consoleKernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
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

            // Report this request to dply's ingest endpoint. Skipped for
            // dply-initiated invocations (ticks / the Logs-page test button)
            // — dply already captures those inline.
            dply_do_functions_report_visit(
                $args, $envFile, $request, $response, $durationMs, $drainLogs
            );

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

        return match ($task) {
            'schedule' => ['schedule:run', []],
            'queue' => ['queue:work', ['--stop-when-empty' => true, '--max-time' => 50]],
            default => throw new RuntimeException('dply command rejected: unknown task "'.$task.'".'),
        };
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
