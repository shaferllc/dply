<?php

use App\Actions\Attributes\TestableEnabled;

describe('TestableEnabled', function () {
    it('can be instantiated', function () {
        expect(new TestableEnabled)->toBeInstanceOf(TestableEnabled::class);
    });

    it('New Feature works correctly', function () {
        // Arrange
        $instance = new TestableEnabled;

        // Act & Assert
        expect($instance)->toBeInstanceOf(TestableEnabled::class);
        // TODO: Add specific test assertions for newFeature()
    });
});
