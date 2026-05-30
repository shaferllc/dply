<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\LlmSynthesizer;
use Illuminate\Support\Facades\Http;

test('llm synthesizer parses json suggestions', function () {
    config([
        'dply_ai.llm.enabled' => true,
        'dply_ai.llm.api_key' => 'test-key',
        'dply_ai.llm.model' => 'gpt-4o-mini',
        'dply_ai.llm.base_url' => 'https://api.openai.com/v1',
    ]);

    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response([
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'narrative' => 'Deploy failed due to memory.',
                        'suggestions' => [[
                            'title' => 'Raise PHP memory',
                            'summary' => 'Increase memory_limit for composer.',
                            'confidence' => 'high',
                            'doc_slug' => 'deploy-troubleshooting',
                            'actions' => [],
                        ]],
                    ], JSON_THROW_ON_ERROR),
                ],
            ]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20],
        ], 200),
    ]);

    $result = app(LlmSynthesizer::class)->synthesizeOpsCopilot(['failure' => ['summary' => 'oom']]);

    expect($result->narrative)->toContain('memory');
    expect($result->suggestions)->toHaveCount(1);
    expect($result->suggestions[0]['title'])->toBe('Raise PHP memory');
});

test('llm synthesizer reports not configured when disabled', function () {
    config(['dply_ai.llm.enabled' => false]);

    expect(app(LlmSynthesizer::class)->isConfigured())->toBeFalse();
});
