<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Edge;

use App\Support\Edge\EdgeBuildLogLintParser;

test('parse extracts lint errors and warnings from build log lines', function () {
    $log = <<<'LOG'
=== dply Edge build abc ===
Config lint: FAILED
[dply.yaml] redirects[0] missing required `from`/`to`.
[dply.yaml] ERROR: dply.yaml parse error: syntax error
LOG;

    $result = EdgeBuildLogLintParser::parse($log, 'dply config lint failed: dply.yaml parse error: syntax error');

    expect($result['lint_failed'])->toBeTrue()
        ->and($result['errors'])->toContain('dply.yaml parse error: syntax error')
        ->and($result['warnings'])->toContain('redirects[0] missing required `from`/`to`.');
});

test('parse returns empty result for unrelated build logs', function () {
    $result = EdgeBuildLogLintParser::parse("npm run build\nDone.\n", 'Git clone failed');

    expect($result['lint_failed'])->toBeFalse()
        ->and($result['errors'])->toBe([])
        ->and($result['warnings'])->toBe([]);
});
