<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Components\TaskCallback;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;
use Tests\TestCase;

uses(TestCase::class);

test('TaskCallback component can be instantiated with required properties', function () {
    $bashFunction = 'curl';
    $url = 'https://api.example.com/webhook';
    $body = '{"status": "completed"}';

    $component = new TaskCallback($bashFunction, $url, $body);

    expect($component)->toBeInstanceOf(TaskCallback::class)
        ->and($component)->toBeInstanceOf(Component::class)
        ->and($component->bashFunction)->toBe($bashFunction)
        ->and($component->url)->toBe($url)
        ->and($component->body)->toBe($body);
});

test('TaskCallback component renders the correct view', function () {
    $component = new TaskCallback('curl', 'https://example.com', '{"data": "test"}');

    $view = $component->render();

    expect($view->name())->toBe('task-runner::task-callback');
});

test('TaskCallback component passes data to the view correctly', function () {
    $bashFunction = 'wget';
    $url = 'https://webhook.site/abc123';
    $body = '{"task_id": 123, "status": "success"}';

    $component = new TaskCallback($bashFunction, $url, $body);

    // Test that the component properties are accessible
    expect($component->bashFunction)->toBe($bashFunction)
        ->and($component->url)->toBe($url)
        ->and($component->body)->toBe($body);

    // Test that the view can be rendered
    $view = $component->render();
    expect($view)->toBeInstanceOf(View::class);
});

test('TaskCallback component handles special characters in body correctly', function () {
    $bashFunction = 'curl';
    $url = 'https://api.example.com/callback';
    $body = '{"message": "Task completed with <script>alert("xss")</script>"}';

    $component = new TaskCallback($bashFunction, $url, $body);

    expect($component->body)->toBe($body);
});

test('TaskCallback component handles empty strings', function () {
    $component = new TaskCallback('', '', '');

    expect($component->bashFunction)->toBe('')
        ->and($component->url)->toBe('')
        ->and($component->body)->toBe('');
});

test('TaskCallback component handles complex URLs', function () {
    $bashFunction = 'curl';
    $url = 'https://api.example.com/webhook?token=abc123&user=john&timestamp='.time();
    $body = '{"complex": "data"}';

    $component = new TaskCallback($bashFunction, $url, $body);

    expect($component->url)->toBe($url);
});

test('TaskCallback component can be used in blade templates', function () {
    $bashFunction = 'curl';
    $url = 'https://example.com/webhook';
    $body = '{"test": true}';

    $component = new TaskCallback($bashFunction, $url, $body);

    // Simulate rendering the component
    $rendered = $component->render();

    expect($rendered)->toBeInstanceOf(View::class);
});

test('TaskCallback component properties are public and accessible', function () {
    $component = new TaskCallback('curl', 'https://example.com', '{"data": "test"}');

    // Test that properties are accessible via reflection
    $reflection = new ReflectionClass($component);

    expect($reflection->getProperty('bashFunction')->isPublic())->toBeTrue()
        ->and($reflection->getProperty('url')->isPublic())->toBeTrue()
        ->and($reflection->getProperty('body')->isPublic())->toBeTrue();
});

test('TaskCallback component can handle different bash functions', function () {
    $functions = ['curl', 'wget', 'httpie', 'fetch'];

    foreach ($functions as $function) {
        $component = new TaskCallback($function, 'https://example.com', '{"test": true}');
        expect($component->bashFunction)->toBe($function);
    }
});

test('TaskCallback component can handle various URL formats', function () {
    $urls = [
        'https://api.example.com/webhook',
        'http://localhost:8000/callback',
        'https://webhook.site/abc123?token=xyz',
        'https://hooks.slack.com/services/T00000000/B00000000/XXXXXXXXXXXXXXXXXXXXXXXX',
    ];

    foreach ($urls as $url) {
        $component = new TaskCallback('curl', $url, '{"test": true}');
        expect($component->url)->toBe($url);
    }
});

test('TaskCallback component can handle JSON bodies with various content', function () {
    $bodies = [
        '{"status": "success"}',
        '{"error": "Something went wrong", "code": 500}',
        '{"data": {"id": 123, "name": "test", "active": true}}',
        '{"message": "Task completed", "timestamp": "'.time().'"}',
    ];

    foreach ($bodies as $body) {
        $component = new TaskCallback('curl', 'https://example.com', $body);
        expect($component->body)->toBe($body);
    }
});

test('TaskCallback component view exists and is accessible', function () {
    expect(view()->exists('task-runner::task-callback'))->toBeTrue();
});
