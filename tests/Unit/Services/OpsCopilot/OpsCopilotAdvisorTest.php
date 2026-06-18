<?php

declare(strict_types=1);

namespace Tests\Unit\Services\OpsCopilot;

use App\Modules\OpsCopilot\Services\OpsCopilotAdvisor;

test('advisor detects php memory exhaustion', function () {
    $suggestions = app(OpsCopilotAdvisor::class)->suggest(
        'PHP Fatal error: Allowed memory size of 134217728 bytes exhausted'
    );

    expect($suggestions)->not->toBeEmpty();
    expect($suggestions[0]->title)->toBe('PHP memory limit exhausted');
});

test('advisor returns generic suggestion when no pattern matches', function () {
    $suggestions = app(OpsCopilotAdvisor::class)->suggest('something utterly unknown xyz');

    expect($suggestions)->toHaveCount(1);
    expect($suggestions[0]->id)->toBe('generic_review_log');
});

test('advisor returns empty list for blank input', function () {
    expect(app(OpsCopilotAdvisor::class)->suggest(''))->toBe([]);
});
