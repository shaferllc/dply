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
use Illuminate\Http\Request;

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

            /** @var \Illuminate\Foundation\Application $app */
            $app = require $root.'/bootstrap/app.php';
            $app->useStoragePath($storage);

            // dply background tick — run the Laravel scheduler or a queue
            // worker instead of handling an HTTP request. dply's own
            // scheduler invokes this every minute for enabled functions.
            $task = dply_do_functions_command($args);
            if ($task !== null) {
                $consoleKernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
                $exitCode = $consoleKernel->call($task[0], $task[1]);

                return [
                    'statusCode' => $exitCode === 0 ? 200 : 500,
                    'headers' => ['content-type' => 'text/plain; charset=utf-8'],
                    'body' => 'dply ran '.$task[0].' — exit '.$exitCode."\n\n".$consoleKernel->output(),
                ];
            }

            $request = dply_do_functions_request($args);

            /** @var Kernel $kernel */
            $kernel = $app->make(Kernel::class);
            $response = $kernel->handle($request);
            $body = $response->getContent();
            $kernel->terminate($request, $response);

            $headers = [];
            foreach ($response->headers->allPreserveCaseWithoutCookies() as $name => $values) {
                $headers[$name] = implode(', ', array_map('strval', (array) $values));
            }

            return [
                'statusCode' => $response->getStatusCode(),
                'headers' => $headers,
                'body' => is_string($body) ? $body : '',
            ];
        } catch (\Throwable $e) {
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
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    function dply_do_functions_command(array $args): ?array
    {
        $headers = is_array($args['__ow_headers'] ?? null) ? $args['__ow_headers'] : [];
        $task = strtolower(trim((string) ($headers['x-dply-run'] ?? '')));
        if ($task === '') {
            return null;
        }

        $secret = (string) getenv('DPLY_COMMAND_SECRET');
        $given = (string) ($headers['x-dply-secret'] ?? '');
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
