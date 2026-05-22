<?php

namespace Tests\Unit\Services\ConfigRevisions;

use App\Services\ConfigRevisions\Diff\ConfigRevisionDiffRegistry;
use App\Services\ConfigRevisions\Diff\PhpFileDiffRenderer;
use App\Services\ConfigRevisions\Diff\WebserverConfigDiffRenderer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DiffRenderersTest extends TestCase
{
    #[Test]
    public function php_file_renderer_produces_unified_diff_lines_for_changed_content(): void
    {
        $renderer = new PhpFileDiffRenderer;

        $out = $renderer->render(
            ['path' => '/etc/php/8.4/cli/php.ini', 'content' => "a\nb\nc\n"],
            ['path' => '/etc/php/8.4/cli/php.ini', 'content' => "a\nBB\nc\n"],
        );

        $this->assertStringContainsString("-b", $out);
        $this->assertStringContainsString("+BB", $out);
    }

    #[Test]
    public function php_file_renderer_returns_empty_string_when_content_unchanged(): void
    {
        $renderer = new PhpFileDiffRenderer;
        $this->assertSame('', $renderer->render(
            ['path' => '/x', 'content' => 'same'],
            ['path' => '/x', 'content' => 'same'],
        ));
    }

    #[Test]
    public function webserver_config_renderer_emits_per_field_blocks_and_marks_unchanged_fields(): void
    {
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
    }

    #[Test]
    public function registry_routes_kinds_to_the_correct_renderer(): void
    {
        $registry = app(ConfigRevisionDiffRegistry::class);

        $this->assertInstanceOf(PhpFileDiffRenderer::class, $registry->rendererFor('php_cli_ini'));
        $this->assertInstanceOf(PhpFileDiffRenderer::class, $registry->rendererFor('php_fpm_ini'));
        $this->assertInstanceOf(PhpFileDiffRenderer::class, $registry->rendererFor('php_pool'));
        $this->assertInstanceOf(WebserverConfigDiffRenderer::class, $registry->rendererFor('webserver_config'));
        $this->assertFalse($registry->supports('unknown_kind'));
    }
}
