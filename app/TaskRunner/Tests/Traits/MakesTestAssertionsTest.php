<?php

declare(strict_types=1);

use App\Modules\TaskRunner\Traits\MakesTestAssertions;
use PHPUnit\Framework\AssertionFailedError;

describe('MakesTestAssertions Trait (Complex)', function () {
    beforeEach(function () {
        $this->testClass = new class
        {
            use MakesTestAssertions;

            public $calls = [];

            public function faked($callback)
            {
                return collect($this->calls);
            }

            public function makeAssertCallback($taskClass, $additionalCallback)
            {
                return fn () => true;
            }
        };
    });

    it('assertDispatched passes if tasks found', function () {
        $this->testClass->calls = [1];
        $this->testClass->assertDispatched('SomeTask');
        expect(true)->toBeTrue();
    });

    it('assertNotDispatched passes if no tasks found', function () {
        $this->testClass->calls = [];
        $this->testClass->assertNotDispatched('SomeTask');
        expect(true)->toBeTrue();
    });

    it('assertDispatchedTimes passes for correct count', function () {
        $this->testClass->calls = [1, 2, 3];
        $this->testClass->assertDispatchedTimes('SomeTask', 3);
        expect(true)->toBeTrue();
    });

    it('assertNotDispatched fails if tasks found', function () {
        $this->testClass->calls = [1];
        expect(fn () => $this->testClass->assertNotDispatched('SomeTask'))->toThrow(AssertionFailedError::class);
    });

    it('assertDispatchedTimes fails for wrong count', function () {
        $this->testClass->calls = [1, 2];
        expect(fn () => $this->testClass->assertDispatchedTimes('SomeTask', 3))->toThrow(AssertionFailedError::class);
    });
});
