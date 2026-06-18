<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Components\TaskShell;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use Tests\TestCase;

uses(TestCase::class);

test('TaskShell component can be instantiated with required properties', function () {
    $setOptions = 'set -euo pipefail';

    $component = new TaskShell($setOptions);

    expect($component)->toBeInstanceOf(TaskShell::class)
        ->and($component)->toBeInstanceOf(Component::class)
        ->and($component->setOptions)->toBe($setOptions);
});

test('TaskShell component renders the correct view', function () {
    $component = new TaskShell('set -euo pipefail');

    $view = $component->render();

    expect($view->name())->toBe('task-runner::task-shell');
});

test('TaskShell component passes data to the view correctly', function () {
    $setOptions = 'set -euo pipefail';

    $component = new TaskShell($setOptions);

    // Test that the component properties are accessible
    expect($component->setOptions)->toBe($setOptions);

    // Test that the view can be rendered
    $view = $component->render();
    expect($view)->toBeInstanceOf(View::class);
});

test('TaskShell component handles different shell options', function () {
    $options = [
        'set -euo pipefail',
        'set -e',
        'set -u',
        'set -o pipefail',
        'set -ex',
        '',
    ];

    foreach ($options as $option) {
        $component = new TaskShell($option);
        expect($component->setOptions)->toBe($option);
    }
});

test('TaskShell component handles empty string options', function () {
    $component = new TaskShell('');

    expect($component->setOptions)->toBe('');
});

test('TaskShell component handles complex shell options', function () {
    $complexOptions = 'set -euo pipefail; export DEBIAN_FRONTEND=noninteractive';

    $component = new TaskShell($complexOptions);

    expect($component->setOptions)->toBe($complexOptions);
});

test('TaskShell component can be used in blade templates', function () {
    $component = new TaskShell('set -euo pipefail');

    // Simulate rendering the component
    $rendered = $component->render();

    expect($rendered)->toBeInstanceOf(View::class);
});

test('TaskShell component properties are public and accessible', function () {
    $component = new TaskShell('set -euo pipefail');

    // Test that properties are accessible via reflection
    $reflection = new ReflectionClass($component);

    expect($reflection->getProperty('setOptions')->isPublic())->toBeTrue();
});

test('TaskShell component view exists and is accessible', function () {
    expect(view()->exists('task-runner::task-shell'))->toBeTrue();
});

test('TaskShell component can handle special characters in options', function () {
    $specialOptions = 'set -euo pipefail; echo "Hello World"; export PATH="/usr/local/bin:$PATH"';

    $component = new TaskShell($specialOptions);

    expect($component->setOptions)->toBe($specialOptions);
});

test('TaskShell component can handle multiline options', function () {
    $multilineOptions = "set -euo pipefail\nexport DEBIAN_FRONTEND=noninteractive\nexport PATH=/usr/local/bin:\$PATH";

    $component = new TaskShell($multilineOptions);

    expect($component->setOptions)->toBe($multilineOptions);
});
