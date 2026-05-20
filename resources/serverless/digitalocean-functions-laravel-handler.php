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

        // Serverless-safe defaults. putenv() does not override values from a
        // committed .env (Dotenv keeps existing env), so an app that ships a
        // serverless-aware .env stays in control.
        foreach ([
            'APP_ENV' => 'production',
            'LOG_CHANNEL' => 'stderr',
            'CACHE_STORE' => 'array',
            'CACHE_DRIVER' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'VIEW_COMPILED_PATH' => $storage.'/framework/views',
            // bootstrap/cache is read-only here — redirect every framework
            // cache file Laravel might (re)write to a writable /tmp path.
            'APP_CONFIG_CACHE' => $bootstrapCache.'/config.php',
            'APP_EVENTS_CACHE' => $bootstrapCache.'/events.php',
            'APP_PACKAGES_CACHE' => $bootstrapCache.'/packages.php',
            'APP_ROUTES_CACHE' => $bootstrapCache.'/routes.php',
            'APP_SERVICES_CACHE' => $bootstrapCache.'/services.php',
        ] as $key => $value) {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv("{$key}={$value}");
        }

        // Laravel needs an APP_KEY to build the encrypter (the cookie
        // middleware resolves it on terminate). If the deployed app ships no
        // key, mint an ephemeral one so it can boot. A real app should set a
        // stable APP_KEY — a per-cold-start key cannot decrypt data written
        // by another instance. putenv() runs before Dotenv, which keeps
        // existing env, so a real .env key still wins.
        $hasAppKey = is_string(getenv('APP_KEY') ?: null) && trim((string) getenv('APP_KEY')) !== '';
        if (! $hasAppKey && is_file($root.'/.env')) {
            foreach (file($root.'/.env', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                if (preg_match('/^\s*APP_KEY\s*=\s*(.+)$/', $line, $m) === 1) {
                    $hasAppKey = trim($m[1], "\"' ") !== '';
                    break;
                }
            }
        }
        if (! $hasAppKey) {
            $generatedKey = 'base64:'.base64_encode(random_bytes(32));
            $_ENV['APP_KEY'] = $_SERVER['APP_KEY'] = $generatedKey;
            putenv('APP_KEY='.$generatedKey);
        }

        try {
            require $root.'/vendor/autoload.php';

            /** @var \Illuminate\Foundation\Application $app */
            $app = require $root.'/bootstrap/app.php';
            $app->useStoragePath($storage);

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
