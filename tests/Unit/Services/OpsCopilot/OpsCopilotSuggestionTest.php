<?php

declare(strict_types=1);

namespace Tests\Unit\Services\OpsCopilot;

use App\Modules\OpsCopilot\Services\OpsCopilotSuggestion;

test('llm suggestion includes source and actions', function () {
    $suggestion = OpsCopilotSuggestion::fromLlm(0, [
        'title' => 'Fix build',
        'summary' => 'Run composer install',
        'confidence' => 'high',
        'doc_slug' => 'deploy-troubleshooting',
        'actions' => [
            ['label' => 'Open deploy', 'url' => 'https://example.test/deploy'],
        ],
    ]);

    $array = $suggestion->toArray();

    expect($array['source'])->toBe('llm');
    expect($array['actions'])->toHaveCount(1);
});
