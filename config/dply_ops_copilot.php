<?php

/*
|--------------------------------------------------------------------------
| Ops Copilot — heuristic patterns + optional LLM synthesis
|--------------------------------------------------------------------------
|
| v1 ships rule-based suggestions from deploy log excerpts. When an API key
| is configured, OpsCopilotLlmAdvisor can augment heuristics (future hook).
|
*/

return [

    'log_excerpt_bytes' => (int) env('DPLY_OPS_COPILOT_LOG_EXCERPT_BYTES', 24_000),

    'llm' => [
        'enabled' => filter_var(env('DPLY_OPS_COPILOT_LLM_ENABLED', false), FILTER_VALIDATE_BOOL),
        'provider' => env('DPLY_OPS_COPILOT_LLM_PROVIDER', 'openai'),
        'model' => env('DPLY_OPS_COPILOT_LLM_MODEL', 'gpt-4o-mini'),
        'api_key' => env('DPLY_OPS_COPILOT_LLM_API_KEY'),
        'timeout_seconds' => (int) env('DPLY_OPS_COPILOT_LLM_TIMEOUT', 45),
    ],

    /*
    | Regex patterns matched against the combined failure summary + log tail.
    | Each match emits one suggestion (first match per pattern wins).
    */
    'heuristics' => [
        [
            'pattern' => '/allowed memory size of \d+ bytes exhausted/i',
            'title' => 'PHP memory limit exhausted',
            'summary' => 'Composer or the app exceeded PHP memory during deploy. Raise the site PHP memory limit or prefix the build with COMPOSER_MEMORY_LIMIT=-1.',
            'doc_slug' => 'deploy-troubleshooting',
        ],
        [
            'pattern' => '/npm ERR!.*ERESOLVE|Could not resolve dependency/i',
            'title' => 'npm peer dependency conflict',
            'summary' => 'npm could not resolve the dependency tree. Pin compatible versions in package.json, or use npm install --legacy-peer-deps in the build command as a temporary unblock.',
            'doc_slug' => 'edge-build-settings',
        ],
        [
            'pattern' => '/Module not found: Can\'t resolve|Cannot find module/i',
            'title' => 'Missing JavaScript module',
            'summary' => 'The build references a file or package that is not installed or not committed. Verify imports, monorepo root (repo_root), and that devDependencies needed at build time are present.',
            'doc_slug' => 'edge-build-settings',
        ],
        [
            'pattern' => '/Permission denied|EACCES/i',
            'title' => 'Filesystem permission error',
            'summary' => 'The deploy user cannot write to a path. Check web directory ownership on the server and that storage/bootstrap/cache paths are writable for Laravel apps.',
            'doc_slug' => 'deploy-troubleshooting',
        ],
        [
            'pattern' => '/Class .* not found|Target class .* does not exist/i',
            'title' => 'Autoload / missing PHP class',
            'summary' => 'PHP could not autoload a class — often a missing composer dump-autoload, a case-sensitive path mismatch, or a file not deployed. Run composer install without --no-dev if the class lives in require-dev during build.',
            'doc_slug' => 'deploy-troubleshooting',
        ],
        [
            'pattern' => '/SQLSTATE\[|connection refused.*5432|connection refused.*3306/i',
            'title' => 'Database connection failed',
            'summary' => 'The deploy or migrate step could not reach the database. Confirm DB credentials in site environment, firewall rules on the server, and that migrations run against the intended host.',
            'doc_slug' => 'databases',
        ],
        [
            'pattern' => '/No application encryption key|APP_KEY/i',
            'title' => 'Missing APP_KEY',
            'summary' => 'Laravel needs APP_KEY in the site environment before artisan commands succeed. Generate one locally (php artisan key:generate --show) and add it under Site → Environment.',
            'doc_slug' => 'environment-variables',
        ],
        [
            'pattern' => '/command not found: (yarn|pnpm|bun|php|composer|npm)/i',
            'title' => 'Build tool not on PATH',
            'summary' => 'The build command references a binary that is not installed on the build runner or server. Pin the runtime version or adjust the build command to use a available toolchain.',
            'doc_slug' => 'edge-build-settings',
        ],
        [
            'pattern' => '/Build failed|build script returned non-zero/i',
            'title' => 'Build script exited with an error',
            'summary' => 'Scroll the log excerpt for the first error above the summary line — that is usually the root cause. Compare build_command and output directory against the framework defaults.',
            'doc_slug' => 'edge-build-settings',
        ],
    ],

];
