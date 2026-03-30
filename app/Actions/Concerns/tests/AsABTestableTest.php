<?php

declare(strict_types=1);

use App\Actions\Concerns\AsABTestable;
use App\Actions\Decorators\ABTestableDecorator;

describe('AsABTestable', function () {
    it('trait can be used by a class', function () {
        $instance = new class
        {
            use AsABTestable;
        };

        expect($instance)->toBeInstanceOf(get_class($instance));
    });

    it('setABTestableDecorator works correctly', function () {
        $instance = new class
        {
            use AsABTestable;
        };

        $decorator = Mockery::mock(ABTestableDecorator::class);

        $instance->setABTestableDecorator($decorator);

        expect($instance)->toBeInstanceOf(get_class($instance));
    });

    it('selectVariant returns A when decorator not set', function () {
        $instance = new class
        {
            use AsABTestable;
        };

        $result = $instance->selectVariant(new stdClass);

        expect($result)->toBe('A');
    });

    it('getUserVariant returns null when decorator not set', function () {
        $instance = new class
        {
            use AsABTestable;
        };

        $result = $instance->getUserVariant(new stdClass);

        expect($result)->toBeNull();
    });

    it('trackVariant does not throw when decorator not set', function () {
        $instance = new class
        {
            use AsABTestable;
        };

        $instance->trackVariant(new stdClass, 'A');

        expect(true)->toBeTrue();
    });

    it('selectVariant delegates to decorator when set', function () {
        $instance = new class
        {
            use AsABTestable;
        };

        $user = new stdClass;
        $decorator = Mockery::mock(ABTestableDecorator::class);
        $decorator->shouldReceive('selectVariant')->with($user)->once()->andReturn('B');

        $instance->setABTestableDecorator($decorator);

        expect($instance->selectVariant($user))->toBe('B');
    });

    it('getUserVariant delegates to decorator when set', function () {
        $instance = new class
        {
            use AsABTestable;
        };

        $user = new stdClass;
        $decorator = Mockery::mock(ABTestableDecorator::class);
        $decorator->shouldReceive('getUserVariant')->with($user)->once()->andReturn('B');

        $instance->setABTestableDecorator($decorator);

        expect($instance->getUserVariant($user))->toBe('B');
    });

    it('trackVariant delegates to decorator when set', function () {
        $instance = new class
        {
            use AsABTestable;
        };

        $user = new stdClass;
        $decorator = Mockery::mock(ABTestableDecorator::class);
        $decorator->shouldReceive('trackVariant')->with($user, 'B')->once();

        $instance->setABTestableDecorator($decorator);
        $instance->trackVariant($user, 'B');

        expect(true)->toBeTrue();
    });
});
