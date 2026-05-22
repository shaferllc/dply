<?php

declare(strict_types=1);

namespace Tests\Feature\Components;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class ExplainerComponentTest extends TestCase
{
    public function test_it_renders_default_what_is_this_label(): void
    {
        $html = Blade::render('<x-explainer><p>Hello.</p></x-explainer>');

        $this->assertStringContainsString('What is this?', $html);
        $this->assertStringContainsString('Hello.', $html);
        // Native disclosure pattern — render uses <details>/<summary>.
        $this->assertStringContainsString('<details', $html);
        $this->assertStringContainsString('<summary', $html);
    }

    public function test_it_accepts_a_custom_title(): void
    {
        $html = Blade::render('<x-explainer title="When to use this"><p>Body.</p></x-explainer>');

        $this->assertStringContainsString('When to use this', $html);
        $this->assertStringNotContainsString('What is this?', $html);
    }

    public function test_warn_tone_applies_amber_classes(): void
    {
        $html = Blade::render('<x-explainer tone="warn"><p>Heads up.</p></x-explainer>');

        $this->assertStringContainsString('amber', $html);
    }

    public function test_default_tone_does_not_apply_amber_classes(): void
    {
        $html = Blade::render('<x-explainer><p>Body.</p></x-explainer>');

        $this->assertStringNotContainsString('amber', $html);
    }

    public function test_slot_content_renders_inside_body(): void
    {
        $html = Blade::render('<x-explainer><p>First.</p><p>Second.</p></x-explainer>');

        $this->assertStringContainsString('First.', $html);
        $this->assertStringContainsString('Second.', $html);
    }
}
