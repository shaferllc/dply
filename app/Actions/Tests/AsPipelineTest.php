<?php

declare(strict_types=1);

namespace App\Actions\Tests;

use Illuminate\Support\Facades\Pipeline;
use Tests\TestCase;

uses(TestCase::class);

it('can run as a pipe in a pipeline, with an explicit asPipeline method', function () {
    $passable = Pipeline::send(new PipelinePassable)
        ->through([
            AsPipelineTestAction::class,
            AsPipelineTestAction::class,
            AsPipelineTestAction::class,
            AsPipelineTestAction::class,
        ])
        ->thenReturn();

    expect($passable)->toBeInstanceOf(PipelinePassable::class)
        ->and($passable->count)->toBe(4);
});

it('can run with an arbitrary via method configured on Pipeline', function () {
    $passable = Pipeline::send(new PipelinePassable)
        ->via('arbitraryMethodThatDoesNotExistOnTheAction')
        ->through([
            AsPipelineTestAction::class,
            app()->make(AsPipelineTestAction::class),
        ])
        ->thenReturn();

    expect($passable)->toBeInstanceOf(PipelinePassable::class)
        ->and($passable->count)->toBe(2);
});

it('can run as a pipe in a pipeline with only one explicit container resolved instance at the bottom of the stack', function () {
    $passable = Pipeline::send(new PipelinePassable)
        ->through([
            AsPipelineTestAction::class, // implicit container resolved instance
            app()->make(AsPipelineTestAction::class), // explicit container resolved instance
        ])
        ->thenReturn();

    expect($passable)->toBeInstanceOf(PipelinePassable::class)
        ->and($passable->count)->toBe(2);
});

it('cannot run as a pipe in a pipeline with an explicit container resolved instance in the middle of the stack', function () {
    $passable = Pipeline::send(new PipelinePassable)
        ->through([
            AsPipelineTestAction::class, // implicit container resolved instance
            app()->make(AsPipelineTestAction::class), // explicit container resolved instance
            AsPipelineTestAction::class, // implicit container resolved instance
            AsPipelineTestAction::class, // implicit container resolved instance
        ])
        ->thenReturn();

    expect($passable)->toBeInstanceOf(PipelinePassable::class)
        ->and($passable->count)->toBe(2);
});

it('cannot run as a pipe in a pipeline as an standalone instance', function () {
    $passable = Pipeline::send(new PipelinePassable)
        ->through([
            new AsPipelineTestAction, // standalone instance
            AsPipelineTestAction::class, // implicit container resolved instance
            app()->make(AsPipelineTestAction::class), // explicit container resolved instance
        ])
        ->thenReturn();

    expect($passable)->toBeNull();
});
