<?php

/*
|--------------------------------------------------------------------------
| dply AI — shared LLM synthesis + per-feature toggles
|--------------------------------------------------------------------------
|
| Platform-managed API key via env. Org BYO keys are a future extension.
| Heuristic advisors run without LLM; synthesis is optional augmentation.
|
*/

return [

    'llm' => [
        'enabled' => env('DPLY_AI_LLM_ENABLED', env('DPLY_OPS_COPILOT_LLM_ENABLED', false)),
        // 'claude' (or 'claude-cli') routes completions through the local `claude` CLI
        // — no API key needed; anything else hits an OpenAI-compatible HTTP endpoint.
        'provider' => env('DPLY_AI_LLM_PROVIDER', env('DPLY_OPS_COPILOT_LLM_PROVIDER', 'openai')),
        'model' => env('DPLY_AI_LLM_MODEL', env('DPLY_OPS_COPILOT_LLM_MODEL', 'gpt-4o-mini')),
        'api_key' => env('DPLY_AI_LLM_API_KEY', env('DPLY_OPS_COPILOT_LLM_API_KEY')),
        'timeout_seconds' => (int) env('DPLY_AI_LLM_TIMEOUT', env('DPLY_OPS_COPILOT_LLM_TIMEOUT', 45)),
        'max_output_tokens' => (int) env('DPLY_AI_LLM_MAX_OUTPUT_TOKENS', 1200),
        'base_url' => env('DPLY_AI_LLM_BASE_URL', 'https://api.openai.com/v1'),
    ],

    'features' => [
        'ops_copilot' => env('DPLY_AI_OPS_COPILOT_ENABLED', true),
        'shared_host' => env('DPLY_AI_SHARED_HOST_ENABLED', true),
        'docs_ask' => env('DPLY_AI_DOCS_ASK_ENABLED', true),
    ],

    'rate_limits' => [
        'per_org_per_hour' => env('DPLY_AI_RATE_LIMIT_ORG_HOUR', 30),
    ],

];
