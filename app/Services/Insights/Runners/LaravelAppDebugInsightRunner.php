<?php

namespace App\Services\Insights\Runners;

use App\Models\InsightFinding;
use App\Models\Server;
use App\Models\Site;
use App\Services\Insights\Contracts\InsightRunnerInterface;
use App\Services\Insights\InsightCandidate;
use App\Services\Sites\DotEnvFileParser;

/**
 * Detect Laravel sites with APP_DEBUG=true and APP_ENV=production. Together
 * they leak stack traces, env vars, and SQL queries to anyone hitting an
 * error page — easy to forget and easy to spot from the env cache without an
 * SSH round-trip.
 *
 * Reads the encrypted env_file_content on the Site row, which is dply's
 * source of truth for what gets pushed to the server's .env.
 */
class LaravelAppDebugInsightRunner implements InsightRunnerInterface
{
    public function __construct(
        protected DotEnvFileParser $parser,
    ) {}

    public function run(Server $server, ?Site $site, array $parameters): array
    {
        if ($site === null || $server->id !== $site->server_id) {
            return [];
        }

        // Skip non-Laravel sites entirely; APP_DEBUG only matters for Laravel.
        if (! $site->isLaravelFrameworkDetected()) {
            return [];
        }

        $envContent = (string) ($site->env_file_content ?? '');
        if ($envContent === '') {
            return [];
        }

        $parsed = $this->parser->parse($envContent);
        $vars = $parsed['variables'] ?? [];

        $appEnv = strtolower(trim((string) ($vars['APP_ENV'] ?? '')));
        $appDebug = strtolower(trim((string) ($vars['APP_DEBUG'] ?? '')));

        // Truthy in Laravel: 'true', '1', 'on', 'yes' (Env::get loose match).
        $debugOn = in_array($appDebug, ['true', '1', 'on', 'yes'], true);
        if (! $debugOn) {
            return [];
        }

        // Only flag if the operator clearly intends a non-development
        // environment — debug-on locally is fine and expected.
        $isProduction = $appEnv === 'production' || $appEnv === 'prod';
        if (! $isProduction) {
            return [];
        }

        return [
            new InsightCandidate(
                insightKey: 'laravel_app_debug_enabled',
                dedupeHash: 'app-debug-on',
                severity: InsightFinding::SEVERITY_CRITICAL,
                title: __('Laravel APP_DEBUG=true in production'),
                body: __('With APP_ENV=production and APP_DEBUG=true, Laravel will render full stack traces and env values on errors. Set APP_DEBUG=false and re-push the .env.'),
                meta: [
                    'signal' => [
                        'app_env' => $appEnv,
                        'app_debug' => $appDebug,
                    ],
                ],
            ),
        ];
    }
}
