<?php

declare(strict_types=1);

namespace Tests\Unit\SiteRuntimeKeyTest;

use App\Enums\SiteType;
use App\Models\Site;

test('returns runtime column when set', function () {
    $site = new Site(['runtime' => 'python']);

    expect($site->runtimeKey())->toBe('python');
});
test('falls back to type enum when runtime is null', function () {
    $site = new Site;
    $site->type = SiteType::Php;

    expect($site->runtimeKey())->toBe('php');
});
test('returns null when neither is set', function () {
    expect((new Site)->runtimeKey())->toBeNull();
});
test('runtime column wins over type when both present', function () {
    $site = new Site(['runtime' => 'ruby']);
    $site->type = SiteType::Php;

    expect($site->runtimeKey())->toBe('ruby');
});
test('internal port is fillable and round trips', function () {
    $site = new Site(['internal_port' => 31234]);

    expect($site->internal_port)->toBe(31234);
});
test('start command is fillable and round trips', function () {
    $site = new Site(['start_command' => 'gunicorn app:app --bind 0.0.0.0:8000']);

    expect($site->start_command)->toBe('gunicorn app:app --bind 0.0.0.0:8000');
});
