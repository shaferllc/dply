<?php

declare(strict_types=1);

namespace Tests\Feature\Components\ExplainerComponentTest;

use Illuminate\Support\Facades\Blade;

test('it renders default what is this label', function () {
    $html = Blade::render('<x-explainer><p>Hello.</p></x-explainer>');

    $this->assertStringContainsString('What is this?', $html);
    $this->assertStringContainsString('Hello.', $html);

    // Native disclosure pattern — render uses <details>/<summary>.
    $this->assertStringContainsString('<details', $html);
    $this->assertStringContainsString('<summary', $html);
});
test('it accepts a custom title', function () {
    $html = Blade::render('<x-explainer title="When to use this"><p>Body.</p></x-explainer>');

    $this->assertStringContainsString('When to use this', $html);
    $this->assertStringNotContainsString('What is this?', $html);
});
test('warn tone applies amber classes', function () {
    $html = Blade::render('<x-explainer tone="warn"><p>Heads up.</p></x-explainer>');

    $this->assertStringContainsString('amber', $html);
});
test('default tone does not apply amber classes', function () {
    $html = Blade::render('<x-explainer><p>Body.</p></x-explainer>');

    $this->assertStringNotContainsString('amber', $html);
});
test('slot content renders inside body', function () {
    $html = Blade::render('<x-explainer><p>First.</p><p>Second.</p></x-explainer>');

    $this->assertStringContainsString('First.', $html);
    $this->assertStringContainsString('Second.', $html);
});
