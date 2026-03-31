<?php

declare(strict_types=1);

use App\Modules\Tags\Actions\Create;
use App\Modules\Tags\Models\Tag;
use App\Modules\Teams\Models\Team;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    // Enable tracing for tests
    Config::set('actions.tracing.enabled', true);
    Config::set('actions.tracing.log_enabled', true);
    Log::spy();
});

test('tracer decorator adds trace metadata to result', function () {
    $team = Team::factory()->create();
    $formData = ['name' => 'Test Tag'];

    $result = Create::run($team, $formData);

    expect($result)
        ->toBeInstanceOf(Tag::class)
        ->and($result->_trace)->toBeArray()
        ->and($result->_trace['trace_id'])->toBeString()
        ->and($result->_trace['span_id'])->toBeString()
        ->and($result->_trace['name'])->toBe('tags.create')
        ->and($result->_trace['success'])->toBeTrue();
});

test('tracer decorator logs span start and end', function () {
    $team = Team::factory()->create();
    $formData = ['name' => 'Test Tag'];

    Create::run($team, $formData);

    Log::shouldHaveReceived('debug')
        ->with('Tracer: Span started', Mockery::on(function ($data) {
            return isset($data['name'])
                && $data['name'] === 'tags.create'
                && isset($data['trace_id'])
                && isset($data['span_id'])
                && isset($data['attributes'])
                && $data['attributes']['team_id'] === $team->id;
        }))
        ->once();

    Log::shouldHaveReceived('debug')
        ->with('Tracer: Span ended', Mockery::on(function ($data) {
            return isset($data['trace_id'])
                && isset($data['span_id'])
                && isset($data['success'])
                && $data['success'] === true;
        }))
        ->once();
});

test('tracer decorator includes custom attributes', function () {
    $team = Team::factory()->create();
    $formData = ['name' => 'Test Tag', 'type' => 'custom'];

    $result = Create::run($team, $formData);

    Log::shouldHaveReceived('debug')
        ->with('Tracer: Span started', Mockery::on(function ($data) {
            return isset($data['attributes'])
                && $data['attributes']['team_id'] === $team->id
                && $data['attributes']['team_name'] === $team->name
                && $data['attributes']['tag_name'] === 'Test Tag'
                && $data['attributes']['tag_type'] === 'custom'
                && isset($data['attributes']['version']);
        }))
        ->once();
});

test('tracer decorator records failure on exception', function () {
    $team = Team::factory()->create();
    $formData = ['name' => ''];

    // This should fail validation, but let's test exception handling
    try {
        Create::run($team, $formData);
    } catch (Exception $e) {
        // Expected to throw validation exception
    }

    Log::shouldHaveReceived('debug')
        ->with('Tracer: Span ended', Mockery::on(function ($data) {
            return isset($data['success'])
                && $data['success'] === false
                && isset($data['exception']);
        }))
        ->once();
});

test('tracer can be disabled via config', function () {
    Config::set('actions.tracing.enabled', false);

    $team = Team::factory()->create();
    $formData = ['name' => 'Test Tag'];

    $result = Create::run($team, $formData);

    // Should not have trace metadata when disabled
    expect($result->_trace ?? null)->toBeNull();

    // Should not log spans
    Log::shouldNotHaveReceived('debug');
});

test('tracer uses custom trace name from action', function () {
    $team = Team::factory()->create();
    $formData = ['name' => 'Test Tag'];

    $result = Create::run($team, $formData);

    expect($result->_trace['name'])->toBe('tags.create');
});
