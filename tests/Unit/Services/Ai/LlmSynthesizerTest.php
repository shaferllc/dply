<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai;

use App\Modules\Ai\Services\LlmSynthesizer;
use Illuminate\Support\Facades\Http;


test('llm synthesizer reports not configured when disabled', function () {
    config(['dply_ai.llm.enabled' => false]);

    expect(app(LlmSynthesizer::class)->isConfigured())->toBeFalse();
});
