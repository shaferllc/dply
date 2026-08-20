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
 * The action filesystem is read-only except for /tmp, so before the
 * framework boots this redirects Laravel's storage path AND compiled views
 * into /tmp. Packaged `bootstrap/cache/*` from `artisan optimize` is
 * preferred for routes/events/services — those files are read-only and that
 * is fine. Config cache is different: `config:cache` freezes build-host
 * `storage_path()` values (logging, views, file cache, sessions). A
 * poisoned `bootstrap/cache/config.php` is discarded (or rewritten under
 * /tmp) so Monolog never mkdir's `/home/dply/.../storage/logs`. File log
 * channels are pointed at stderr so they never create a directory.
 *
 * The bootstrapped Application is reused across invocations in the same
 * container (Bref-style), capped by DPLY_WARM_MAX_REQUESTS (default 250).
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
use Illuminate\Support\Facades\Facade;
use Monolog\Handler\StreamHandler;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        static $warmApp = null;
        static $warmHits = 0;

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

        // Storage + compiled views always live in /tmp — they are written
        // at runtime. Set both Laravel 11+ env names so storage_path()
        // cannot fall back to a baked build-host path.
        $setEnv('APP_STORAGE_PATH', $storage);
        $setEnv('LARAVEL_STORAGE_PATH', $storage);
        $setEnv('VIEW_COMPILED_PATH', $storage.'/framework/views');

        // Prefer packaged bootstrap/cache from `artisan optimize` for
        // routes/events/services. Config cache is sanitized or discarded
        // when it still has build-host absolute paths.
        foreach (dply_do_functions_bootstrap_cache_env($root, $bootstrapCache, $storage) as $key => $value) {
            $setEnv($key, $value);
        }

        // Serverless-safe driver defaults — applied ONLY when the app's .env
        // does not set them, so provisioning Redis (CACHE_STORE=redis) or a
        // database queue stays in the operator's control. Cookie sessions
        // survive across containers; `array` would lose the session on every
        // cold start.
        foreach ([
            'APP_ENV' => 'production',
            'LOG_CHANNEL' => 'stderr',
            'CACHE_STORE' => 'array',
            'CACHE_DRIVER' => 'array',
            'SESSION_DRIVER' => 'cookie',
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

        $headers = is_array($args['__ow_headers'] ?? null) ? $args['__ow_headers'] : [];
        $isCommand = trim((string) ($headers['x-dply-run'] ?? '')) !== '';

        // Durable maintenance — bound `__dply_maintenance` or DPLY_MAINTENANCE
        // in the packaged .env. `/tmp` `down` is lost on cold start, so we
        // never rely on it as the source of truth. Command ticks (scheduler /
        // queue / artisan) still run so operators can drain work and bring
        // the app back.
        $maintenance = dply_do_functions_maintenance_enabled($args, $envFile);
        if ($maintenance && ! $isCommand) {
            return dply_do_functions_maintenance_response($corsPolicy, $args);
        }

        try {
            require $root.'/vendor/autoload.php';

            $maxWarm = dply_do_functions_warm_max_requests($envFile);
            if ($warmApp !== null && $warmHits >= $maxWarm) {
                if (class_exists(Facade::class)) {
                    Facade::clearResolvedInstances();
                }
                $warmApp = null;
                $warmHits = 0;
                gc_collect_cycles();
            }

            if ($warmApp instanceof Application) {
                $app = $warmApp;
                $warmHits++;
            } else {
                /** @var Application $app */
                $app = require $root.'/bootstrap/app.php';
                $app->useStoragePath($storage);

                // Config cache (or config/*.php) may still name a build-host
                // log/view/cache path. Rewrite those onto /tmp / stderr
                // before anything resolves the logger.
                dply_do_functions_remap_runtime_paths($app, $storage, $root);

                // Register the dply Queue connection, the shared lock store, and
                // the failed-job provider, if this function has a namespace.
                dply_do_functions_register_queue($app, $envFile);
                dply_do_functions_register_queue_locks($app, $envFile);
                dply_do_functions_register_failed_jobs($app, $envFile);

                $warmApp = $app;
                $warmHits = 1;
            }

            if ($maintenance) {
                dply_do_functions_write_down_file($storage);
            } else {
                @unlink($storage.'/framework/down');
            }

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

            $path = '/'.ltrim((string) ($args['__ow_path'] ?? '/'), '/');
            $public = dply_do_functions_off_function_assets($envFile, $path)
                ? null
                : dply_do_functions_public_response($root, $path);
            if ($public !== null) {
                if ($corsPolicy !== null) {
                    $public['headers'] += dply_do_functions_cors_headers($corsPolicy, $args);
                }

                return $public;
            }

            $request = dply_do_functions_request($args, $envFile);

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
            $body = dply_do_functions_response_body($response);
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

            return dply_do_functions_web_response(
                $response->getStatusCode(),
                $headers,
                $body,
            );
        } catch (Throwable $e) {
            // OpenWhisk would otherwise swallow this behind a generic
            // "error processing your request" — surface the real cause.
            fwrite(STDERR, 'dply adapter error: '.$e.PHP_EOL);

            return [
                'statusCode' => 500,
                'headers' => ['content-type' => 'application/json'],
                'body' => (string) json_encode([
                    'error' => 'The Laravel app failed to handle this request.',
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                    'at' => $e->getFile().':'.$e->getLine(),
                    'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 20),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ];
        }
    }
}

if (! function_exists('dply_do_functions_bootstrap_cache_env')) {
    /**
     * Map Laravel cache-path env keys to packaged bootstrap/cache files when
     * they exist in the zip, otherwise to writable /tmp copies.
     *
     * Config cache is special: a file that still names `/home/dply/...`
     * storage paths is discarded (APP_CONFIG_CACHE points at a missing
     * /tmp file so Laravel loads `config/*.php`) or rewritten under /tmp.
     *
     * @return array<string, string>
     */
    function dply_do_functions_bootstrap_cache_env(string $root, string $tmpBootstrap, string $storage = '/tmp/dply-storage'): array
    {
        $packaged = rtrim($root, '/').'/bootstrap/cache';
        $files = [
            'APP_CONFIG_CACHE' => 'config.php',
            'APP_EVENTS_CACHE' => 'events.php',
            'APP_PACKAGES_CACHE' => 'packages.php',
            'APP_ROUTES_CACHE' => 'routes.php',
            'APP_SERVICES_CACHE' => 'services.php',
        ];

        $out = [];
        foreach ($files as $key => $file) {
            $packagedFile = $packaged.'/'.$file;
            if ($key === 'APP_CONFIG_CACHE') {
                $out[$key] = dply_do_functions_runtime_config_cache($packagedFile, $tmpBootstrap.'/config.php', $root, $storage);

                continue;
            }
            $out[$key] = is_file($packagedFile) ? $packagedFile : $tmpBootstrap.'/'.$file;
        }

        return $out;
    }
}

if (! function_exists('dply_do_functions_runtime_config_cache')) {
    /**
     * Use the packaged config cache only when it has no build-host storage
     * paths. Otherwise write a sanitized copy under /tmp, or leave that
     * file missing so Laravel rebuilds from `config/*.php`.
     */
    function dply_do_functions_runtime_config_cache(string $packagedFile, string $tmpFile, string $runtimeRoot, string $storage): string
    {
        if (! is_file($packagedFile)) {
            return $tmpFile;
        }

        $contents = (string) @file_get_contents($packagedFile);
        if ($contents === '' || ! dply_do_functions_config_file_looks_poisoned($contents, $runtimeRoot)) {
            return $packagedFile;
        }

        try {
            $config = include $packagedFile;
            if (! is_array($config)) {
                return $tmpFile;
            }

            $sanitized = dply_do_functions_sanitize_cached_config($config, $runtimeRoot, $storage);
            $exported = '<?php return '.var_export($sanitized, true).';'.PHP_EOL;
            if (@file_put_contents($tmpFile, $exported) === false) {
                return $tmpFile;
            }

            return $tmpFile;
        } catch (Throwable) {
            return $tmpFile;
        }
    }
}

if (! function_exists('dply_do_functions_config_file_looks_poisoned')) {
    /**
     * True when a packaged config.php still names an absolute storage path
     * that is not under /tmp or the running action root.
     */
    function dply_do_functions_config_file_looks_poisoned(string $contents, string $runtimeRoot): bool
    {
        if (str_contains($contents, 'serverless-repositories')) {
            return true;
        }

        if (preg_match('#([\'"])(/(?:home|var/www|opt)/[^\'"]+/storage/(?:logs|framework|app))#', $contents) === 1) {
            return true;
        }

        if (preg_match_all('#([\'"])(/(?!tmp/)[^\'"]+/storage/(?:logs|framework)(?:/[^\'"]*)?)\\1#', $contents, $matches) === false) {
            return false;
        }

        $root = rtrim($runtimeRoot, '/').'/';
        foreach ($matches[2] ?? [] as $path) {
            if (! str_starts_with((string) $path, $root) && ! str_starts_with((string) $path, '/tmp/')) {
                return true;
            }
        }

        return false;
    }
}

if (! function_exists('dply_do_functions_detect_build_root')) {
    /**
     * Infer the Laravel root used when config was cached, from baked
     * `.../storage/logs` or `.../storage/framework` paths.
     *
     * @param  array<string, mixed>  $config
     */
    function dply_do_functions_detect_build_root(array $config): ?string
    {
        $found = [];
        $walk = static function ($value) use (&$walk, &$found): void {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $walk($item);
                }

                return;
            }

            if (! is_string($value) || ! str_starts_with($value, '/') || str_starts_with($value, '/tmp/')) {
                return;
            }

            if (preg_match('#^(.+)/(storage/(?:logs|framework|app)(?:/|$))#', $value, $m) === 1) {
                $found[] = $m[1];
            }
        };
        $walk($config);

        if ($found === []) {
            return null;
        }

        $counts = array_count_values($found);
        arsort($counts);

        return (string) array_key_first($counts);
    }
}

if (! function_exists('dply_do_functions_sanitize_cached_config')) {
    /**
     * Rewrite baked build-host paths onto the running action root / /tmp
     * storage, and point file log channels at stderr so Monolog never mkdir's.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    function dply_do_functions_sanitize_cached_config(array $config, string $runtimeRoot, string $runtimeStorage): array
    {
        $buildRoot = dply_do_functions_detect_build_root($config);
        $runtimeRoot = rtrim($runtimeRoot, '/');
        $runtimeStorage = rtrim($runtimeStorage, '/');

        $rewrite = static function ($value) use (&$rewrite, $buildRoot, $runtimeRoot, $runtimeStorage) {
            if (is_array($value)) {
                foreach ($value as $key => $item) {
                    $value[$key] = $rewrite($item);
                }

                return $value;
            }

            if (! is_string($value) || $value === '') {
                return $value;
            }

            if (is_string($buildRoot) && $buildRoot !== '') {
                $buildStorage = $buildRoot.'/storage';
                if (str_starts_with($value, $buildStorage)) {
                    return $runtimeStorage.substr($value, strlen($buildStorage));
                }
                if (str_starts_with($value, $buildRoot)) {
                    return $runtimeRoot.substr($value, strlen($buildRoot));
                }
            }

            if (preg_match('#^/.+/(storage/(?:logs|framework|app)(/.*)?)$#', $value, $m) === 1
                && ! str_starts_with($value, '/tmp/')
                && ! str_starts_with($value, $runtimeRoot.'/')) {
                return $runtimeStorage.substr($m[1], strlen('storage'));
            }

            return $value;
        };

        $config = $rewrite($config);

        foreach (['single', 'daily'] as $channel) {
            $path = $config['logging']['channels'][$channel]['path'] ?? null;
            if (is_string($path) && $path !== '' && ! str_starts_with($path, 'php://') && ! str_starts_with($path, '/dev/')) {
                $config['logging']['channels'][$channel]['path'] = 'php://stderr';
            }
        }

        return $config;
    }
}

if (! function_exists('dply_do_functions_is_unsafe_runtime_path')) {
    /**
     * Absolute filesystem path that is neither /tmp nor the running action.
     */
    function dply_do_functions_is_unsafe_runtime_path(string $path, string $runtimeRoot): bool
    {
        if ($path === '' || str_starts_with($path, 'php://') || str_starts_with($path, '/dev/')) {
            return false;
        }

        if (! str_starts_with($path, '/')) {
            return false;
        }

        if (str_starts_with($path, '/tmp/')) {
            return false;
        }

        $root = rtrim($runtimeRoot, '/').'/';

        return ! str_starts_with($path, $root);
    }
}

if (! function_exists('dply_do_functions_remap_runtime_paths')) {
    /**
     * After the Application exists, force file-backed Laravel paths under
     * /tmp (or stderr) so a leftover config cache cannot mkdir on a
     * read-only filesystem.
     */
    function dply_do_functions_remap_runtime_paths(mixed $app, string $storage, string $runtimeRoot): void
    {
        try {
            $config = $app->make('config');
        } catch (Throwable) {
            return;
        }

        $storage = rtrim($storage, '/');

        $config->set('view.compiled', $storage.'/framework/views');
        $config->set('session.files', $storage.'/framework/sessions');

        if ($config->get('cache.stores.file') !== null) {
            $config->set('cache.stores.file.path', $storage.'/framework/cache/data');
        }

        foreach (['local' => '/app', 'public' => '/app/public'] as $disk => $suffix) {
            $diskRoot = $config->get('filesystems.disks.'.$disk.'.root');
            if (is_string($diskRoot) && dply_do_functions_is_unsafe_runtime_path($diskRoot, $runtimeRoot)) {
                $config->set('filesystems.disks.'.$disk.'.root', $storage.$suffix);
            }
        }

        foreach (['single', 'daily'] as $channel) {
            $path = $config->get('logging.channels.'.$channel.'.path');
            if (is_string($path) && $path !== '' && ! str_starts_with($path, 'php://') && ! str_starts_with($path, '/dev/')) {
                $config->set('logging.channels.'.$channel.'.path', 'php://stderr');
            }
        }
    }
}

if (! function_exists('dply_do_functions_warm_max_requests')) {
    /**
     * @param  array<string, string>  $envFile
     */
    function dply_do_functions_warm_max_requests(array $envFile): int
    {
        $raw = trim((string) ($envFile['DPLY_WARM_MAX_REQUESTS'] ?? (getenv('DPLY_WARM_MAX_REQUESTS') ?: '250')));
        $n = (int) $raw;

        return max(1, min(10000, $n > 0 ? $n : 250));
    }
}

if (! function_exists('dply_do_functions_maintenance_enabled')) {
    /**
     * @param  array<string, mixed>  $args
     * @param  array<string, string>  $envFile
     */
    function dply_do_functions_maintenance_enabled(array $args, array $envFile): bool
    {
        $bound = $args['__dply_maintenance'] ?? null;
        if ($bound === true || $bound === 1 || $bound === '1' || $bound === 'true' || $bound === 'on' || $bound === 'yes') {
            return true;
        }

        $env = strtolower(trim((string) ($envFile['DPLY_MAINTENANCE'] ?? (getenv('DPLY_MAINTENANCE') ?: ''))));

        return in_array($env, ['1', 'true', 'yes', 'on'], true);
    }
}

if (! function_exists('dply_do_functions_write_down_file')) {
    /**
     * Mirror durable maintenance into Laravel's down file so the framework
     * middleware agrees with the handler. The file itself is not durable
     * (/tmp); the env / bound parameter is.
     */
    function dply_do_functions_write_down_file(string $storage): void
    {
        $dir = $storage.'/framework';
        if (! is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        @file_put_contents($dir.'/down', json_encode([
            'except' => [],
            'redirect' => null,
            'retry' => 60,
            'refresh' => 15,
            'secret' => null,
            'status' => 503,
            'template' => null,
        ], JSON_THROW_ON_ERROR));
    }
}

if (! function_exists('dply_do_functions_maintenance_response')) {
    /**
     * @param  array<string, mixed>|null  $corsPolicy
     * @param  array<string, mixed>  $args
     * @return array{statusCode: int, headers: array<string, string>, body: string}
     */
    function dply_do_functions_maintenance_response(?array $corsPolicy, array $args): array
    {
        $headers = [
            'content-type' => 'text/html; charset=utf-8',
            'retry-after' => '60',
        ];
        if ($corsPolicy !== null) {
            $headers += dply_do_functions_cors_headers($corsPolicy, $args);
        }

        return [
            'statusCode' => 503,
            'headers' => $headers,
            'body' => '<!DOCTYPE html><html><head><title>Maintenance</title></head><body><h1>Be right back.</h1><p>This application is down for maintenance.</p></body></html>',
        ];
    }
}

if (! function_exists('dply_do_functions_off_function_assets')) {
    /**
     * True when Vite/build assets are published off-function (ASSET_URL is a
     * different origin than APP_URL) so this function must not serve /build
     * and trip the 1 MB HTTP response cap on fonts/CSS.
     *
     * @param  array<string, string>  $envFile
     */
    function dply_do_functions_off_function_assets(array $envFile, string $path): bool
    {
        $assetUrl = rtrim((string) ($envFile['ASSET_URL'] ?? ''), '/');
        $appUrl = rtrim((string) ($envFile['APP_URL'] ?? ''), '/');
        if ($assetUrl === '' || $appUrl === '' || strcasecmp($assetUrl, $appUrl) === 0) {
            return false;
        }

        $relative = '/'.ltrim($path, '/');

        return $relative === '/build' || str_starts_with($relative, '/build/');
    }
}

if (! function_exists('dply_do_functions_artisan_allowlist')) {
    /**
     * Keep in sync with App\Modules\Serverless\Support\ServerlessArtisan::ALLOWLIST.
     *
     * @return list<string>
     */
    function dply_do_functions_artisan_allowlist(): array
    {
        return [
            'about',
            'optimize', 'optimize:clear',
            'config:cache', 'config:clear',
            'route:cache', 'route:clear', 'route:list',
            'view:cache', 'view:clear',
            'event:cache', 'event:clear',
            'cache:clear',
            'queue:restart',
            'migrate', 'migrate:status',
            'down', 'up',
            'storage:link',
        ];
    }
}

if (! function_exists('dply_do_functions_artisan_signature')) {
    function dply_do_functions_artisan_signature(string $secret, string $command): string
    {
        return hash_hmac('sha256', "artisan\n".$command, $secret);
    }
}

if (! function_exists('dply_do_functions_parse_artisan')) {
    /**
     * Parse an allowlisted artisan invocation into Kernel::call() arguments.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    function dply_do_functions_parse_artisan(string $command): array
    {
        $command = trim($command);
        if ($command === '' || preg_match('/[;|&`$()\\\\]|\\n|\\r/', $command) === 1) {
            throw new RuntimeException('dply command rejected: malformed artisan command.');
        }

        $parts = preg_split('/\s+/', $command) ?: [];
        $name = (string) array_shift($parts);
        if ($name === 'php') {
            $next = (string) array_shift($parts);
            if ($next !== 'artisan') {
                throw new RuntimeException('dply command rejected: unknown artisan command.');
            }
            $name = (string) array_shift($parts);
        } elseif ($name === 'artisan') {
            $name = (string) array_shift($parts);
        }

        if ($name === '' || ! in_array($name, dply_do_functions_artisan_allowlist(), true)) {
            throw new RuntimeException('dply command rejected: artisan command is not allowlisted.');
        }

        $params = [];
        foreach ($parts as $part) {
            if (! str_starts_with($part, '--')) {
                throw new RuntimeException('dply command rejected: positional artisan arguments are not allowed.');
            }

            $opt = substr($part, 2);
            if ($opt === '' || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9:_-]*(=.*)?$/', $opt)) {
                throw new RuntimeException('dply command rejected: malformed artisan option.');
            }

            if (str_contains($opt, '=')) {
                [$key, $value] = explode('=', $opt, 2);
                $params['--'.$key] = $value;
            } else {
                $params['--'.$opt] = true;
            }
        }

        if ($name === 'migrate' && ! array_key_exists('--force', $params)) {
            $params['--force'] = true;
        }

        return [$name, $params];
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

        if ($task === 'artisan') {
            $command = trim((string) ($headers['x-dply-artisan'] ?? ''));
            $signature = trim((string) ($headers['x-dply-signature'] ?? ''));
            $expected = dply_do_functions_artisan_signature($secret, $command);
            if ($command === '' || $signature === '' || ! hash_equals($expected, $signature)) {
                throw new RuntimeException('dply command rejected: invalid artisan signature.');
            }

            return dply_do_functions_parse_artisan($command);
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
        static $installedOn = null;

        $GLOBALS['dply_do_functions_job_queued'] = false;

        try {
            $dispatcher = $app->make(Dispatcher::class);
        } catch (Throwable) {
            // No event dispatcher — nothing to watch. The safety-net tick
            // still drains this app, just at the old latency.
            return static function (): void {};
        }

        if ($installedOn !== $dispatcher) {
            $dispatcher->listen(
                JobQueued::class,
                static function (): void {
                    $GLOBALS['dply_do_functions_job_queued'] = true;
                },
            );
            $installedOn = $dispatcher;
        }

        return static function () use ($envFile): void {
            if (empty($GLOBALS['dply_do_functions_job_queued'])) {
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

if (! function_exists('dply_do_functions_is_internal_host')) {
    /**
     * Hosts that must never become APP_URL / asset() roots. DigitalOcean
     * Functions (OpenWhisk) invokes the action with Host: ccontroller — if
     * that leaks into Laravel's URL generator, Vite emits
     * https://ccontroller/build/assets/… and the browser cannot resolve it.
     */
    function dply_do_functions_is_internal_host(string $host): bool
    {
        $host = strtolower(trim($host));

        return $host === ''
            || in_array($host, ['ccontroller', 'controller', 'localhost', '127.0.0.1'], true)
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal');
    }
}

if (! function_exists('dply_do_functions_public_host')) {
    /**
     * The hostname the function should present as — the dply-proxied
     * friendly host (X-Forwarded-Host) or APP_URL, never the OpenWhisk
     * controller.
     *
     * @param  array<string, mixed>  $headers
     * @param  array<string, string>  $envFile
     */
    function dply_do_functions_public_host(array $headers, array $envFile): string
    {
        $candidates = [
            $headers['x-forwarded-host'] ?? $headers['X-Forwarded-Host'] ?? '',
            (string) parse_url((string) ($envFile['APP_URL'] ?? ''), PHP_URL_HOST),
            (string) parse_url((string) ($envFile['ASSET_URL'] ?? ''), PHP_URL_HOST),
            $headers['host'] ?? $headers['Host'] ?? '',
        ];

        foreach ($candidates as $candidate) {
            $host = strtolower(trim(explode(',', (string) $candidate)[0]));
            $host = explode(':', $host)[0];
            if ($host !== '' && ! dply_do_functions_is_internal_host($host)) {
                return $host;
            }
        }

        return '';
    }
}

if (! function_exists('dply_do_functions_content_type')) {
    /**
     * @param  array<string, string>  $headers
     */
    function dply_do_functions_content_type(array $headers): string
    {
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === 'content-type') {
                return strtolower(trim(explode(';', (string) $value)[0]));
            }
        }

        return '';
    }
}

if (! function_exists('dply_do_functions_is_binary_body')) {
    /**
     * OpenWhisk (Pekko Http) base64-decodes the body when the content-type
     * is binary. Sending raw bytes — or leaving SVG/fonts as text — yields
     * the platform's generic "error processing your request".
     *
     * @param  array<string, string>  $headers
     */
    function dply_do_functions_is_binary_body(string $body, array $headers): bool
    {
        $type = dply_do_functions_content_type($headers);

        if ($type === '' || str_starts_with($type, 'text/')) {
            return $body !== '' && ! mb_check_encoding($body, 'UTF-8');
        }

        // Character types Pekko leaves as text. Encoding these would send
        // base64 to the browser instead of HTML/CSS/JS/JSON.
        if (in_array($type, [
            'application/json',
            'application/ld+json',
            'application/json-patch+json',
            'application/problem+json',
            'application/manifest+json',
            'application/javascript',
            'application/ecmascript',
            'application/xml',
            'application/xml-dtd',
            'application/xhtml+xml',
            'application/x-www-form-urlencoded',
            'application/graphql',
        ], true)) {
            return false;
        }

        // image/svg+xml is XML but Pekko marks it Binary — encode it.
        if ($type !== 'image/svg+xml' && (str_ends_with($type, '+json') || str_ends_with($type, '+xml'))) {
            return false;
        }

        foreach (['image/', 'audio/', 'video/', 'font/'] as $prefix) {
            if (str_starts_with($type, $prefix)) {
                return true;
            }
        }

        foreach ([
            'application/octet-stream',
            'application/pdf',
            'application/zip',
            'application/gzip',
            'application/wasm',
            'application/font',
            'application/x-font',
            'application/vnd.',
        ] as $prefix) {
            if ($type === $prefix || str_starts_with($type, $prefix)) {
                return true;
            }
        }

        return $body !== '' && ! mb_check_encoding($body, 'UTF-8');
    }
}

if (! function_exists('dply_do_functions_web_response')) {
    /**
     * Shape a web-action result so OpenWhisk can JSON-encode it and, for
     * binary content-types, base64-decode it back to bytes for the client.
     *
     * @param  array<string, string>  $headers
     * @return array{statusCode: int, headers: array<string, string>, body: string}
     */
    function dply_do_functions_web_response(int $status, array $headers, string $body): array
    {
        if (dply_do_functions_is_binary_body($body, $headers)) {
            $body = base64_encode($body);
        }

        return [
            'statusCode' => $status,
            'headers' => $headers,
            'body' => $body,
        ];
    }
}

if (! function_exists('dply_do_functions_response_body')) {
    /**
     * Illuminate's getContent() is empty for file() / download() / streamed
     * replies — those still have to reach OpenWhisk as a string.
     */
    function dply_do_functions_response_body(Response $response): string
    {
        $content = $response->getContent();
        if (is_string($content) && $content !== '') {
            return $content;
        }

        if ($response instanceof BinaryFileResponse) {
            $file = $response->getFile();
            if ($file->isFile()) {
                return (string) file_get_contents($file->getPathname());
            }
        }

        if ($response instanceof StreamedResponse) {
            ob_start();
            $response->sendContent();

            return (string) ob_get_clean();
        }

        return is_string($content) ? $content : '';
    }
}

if (! function_exists('dply_do_functions_public_response')) {
    /**
     * Serve a file from public/ the way nginx would in front of Laravel.
     * DigitalOcean Functions has no webserver, so /build/assets/*.css would
     * otherwise fall through to a Laravel 404 (or the app's catch-all).
     *
     * @return array{statusCode: int, headers: array<string, string>, body: string}|null
     */
    function dply_do_functions_public_response(string $root, string $path): ?array
    {
        $relative = ltrim($path, '/');
        if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '..')) {
            return null;
        }

        $publicRoot = realpath(rtrim($root, '/').'/public');
        if ($publicRoot === false || ! is_dir($publicRoot)) {
            return null;
        }

        $realFile = realpath($publicRoot.'/'.$relative);
        if ($realFile === false || ! is_file($realFile)) {
            return null;
        }
        if (! str_starts_with($realFile, $publicRoot.DIRECTORY_SEPARATOR)) {
            return null;
        }

        $mime = match (strtolower((string) pathinfo($realFile, PATHINFO_EXTENSION))) {
            'css' => 'text/css',
            'js', 'mjs' => 'application/javascript',
            'svg' => 'image/svg+xml',
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
            'json', 'map' => 'application/json',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'ico' => 'image/x-icon',
            'txt' => 'text/plain; charset=utf-8',
            'xml' => 'application/xml',
            'pdf' => 'application/pdf',
            'wasm' => 'application/wasm',
            default => (mime_content_type($realFile) ?: 'application/octet-stream'),
        };

        return dply_do_functions_web_response(200, [
            'content-type' => $mime,
            'cache-control' => 'public, max-age=31536000, immutable',
        ], (string) file_get_contents($realFile));
    }
}

if (! function_exists('dply_do_functions_request')) {
    /**
     * Rebuild an Illuminate request from an OpenWhisk raw web-action event.
     *
     * @param  array<string, mixed>  $args
     * @param  array<string, string>  $envFile
     */
    function dply_do_functions_request(array $args, array $envFile = []): Request
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

        $publicHost = dply_do_functions_public_host($headers, $envFile);
        if ($publicHost !== '') {
            $server['HTTP_HOST'] = $publicHost;
            $server['SERVER_NAME'] = $publicHost;
            $server['HTTPS'] = 'on';
            $server['SERVER_PORT'] = '443';
            $server['REQUEST_SCHEME'] = 'https';
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
            $logger = $app->make('log')->channel()->getLogger();
            $logger->pushHandler($handler);

            return static function () use ($logger, $handler, $stream): array {
                try {
                    $handler->close();
                    rewind($stream);
                    $raw = rtrim((string) stream_get_contents($stream), "\n");
                    fclose($stream);
                    $logger->popHandler();

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
