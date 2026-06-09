<?php

namespace Tests\Unit\Services\ConfigRevisions\DiffRenderersTest;

use App\Services\ConfigRevisions\Diff\ConfigRevisionDiffRegistry;
use App\Services\ConfigRevisions\Diff\PhpFileDiffRenderer;
use App\Services\ConfigRevisions\Diff\WebserverConfigDiffRenderer;

test('php file renderer produces unified diff lines for changed content', function () {
    $renderer = new PhpFileDiffRenderer;

    $out = $renderer->render(
        ['path' => '/etc/php/8.4/cli/php.ini', 'content' => "a\nb\nc\n"],
        ['path' => '/etc/php/8.4/cli/php.ini', 'content' => "a\nBB\nc\n"],
    );

    $this->assertStringContainsString('-b', $out);
    $this->assertStringContainsString('+BB', $out);
});

test('php file renderer returns empty string when content unchanged', function () {
    $renderer = new PhpFileDiffRenderer;
    expect($renderer->render(
        ['path' => '/x', 'content' => 'same'],
        ['path' => '/x', 'content' => 'same'],
    ))->toBe('');
});

test('webserver config renderer emits per field blocks and marks unchanged fields', function () {
    $renderer = new WebserverConfigDiffRenderer;

    $a = [
        'mode' => 'layered',
        'before_body' => '',
        'main_snippet_body' => "location /api {\n  proxy_pass http://old;\n}\n",
        'after_body' => '',
        'full_override_body' => null,
    ];
    $b = [
        'mode' => 'layered',
        'before_body' => '',
        'main_snippet_body' => "location /api {\n  proxy_pass http://new;\n}\n",
        'after_body' => '',
        'full_override_body' => null,
    ];

    $out = $renderer->render($a, $b);

    $this->assertStringContainsString('main snippet', $out);
    $this->assertStringContainsString('before layer', $out);
    $this->assertStringContainsString('(unchanged)', $out, 'unchanged fields should collapse');
    $this->assertStringContainsString('proxy_pass', $out);
});

test('registry routes kinds to the correct renderer', function () {
    $registry = app(ConfigRevisionDiffRegistry::class);

    expect($registry->rendererFor('php_cli_ini'))->toBeInstanceOf(PhpFileDiffRenderer::class);
    expect($registry->rendererFor('php_fpm_ini'))->toBeInstanceOf(PhpFileDiffRenderer::class);
    expect($registry->rendererFor('php_pool'))->toBeInstanceOf(PhpFileDiffRenderer::class);
    expect($registry->rendererFor('webserver_config'))->toBeInstanceOf(WebserverConfigDiffRenderer::class);
    expect($registry->supports('unknown_kind'))->toBeFalse();
});
