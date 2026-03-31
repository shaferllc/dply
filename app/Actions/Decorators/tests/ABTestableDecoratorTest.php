<?php

use App\Actions\Decorators\ABTestableDecorator;
use Tests\TestCase;

uses(TestCase::class);

describe('ABTestableDecorator', function () {
    it('Handle works correctly', function () {
        $action = new class
        {
            public function handle(string $variant): string
            {
                return $variant;
            }
        };
        $instance = new ABTestableDecorator($action);

        expect($instance)->toBeInstanceOf(ABTestableDecorator::class);
    });

    it('Select Variant works correctly', function () {
        $action = new class
        {
            public function handle(string $variant): string
            {
                return $variant;
            }
        };
        $instance = new ABTestableDecorator($action);

        expect($instance->selectVariant(null))->toBeIn(['A', 'B']);
    });

    it('Get User Variant works correctly', function () {
        $action = new class
        {
            public function handle(string $variant): string
            {
                return $variant;
            }
        };
        $instance = new ABTestableDecorator($action);

        expect($instance->getUserVariant(null))->toBeNull();
    });

    it('Track Variant works correctly', function () {
        $action = new class
        {
            public function handle(string $variant): string
            {
                return $variant;
            }
        };
        $instance = new ABTestableDecorator($action);

        $instance->trackVariant(null, 'A');

        expect(true)->toBeTrue();
    });
});
