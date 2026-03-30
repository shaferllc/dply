<?php

declare(strict_types=1);

use App\Actions\Attributes\TransformMode;

describe('TransformMode', function () {
    it('can be instantiated with default', function () {
        $attr = new TransformMode;

        expect($attr)->toBeInstanceOf(TransformMode::class);
        expect($attr->mode)->toBe('nested');
    });

    it('can be instantiated with custom mode', function () {
        $attr = new TransformMode('direct');

        expect($attr->mode)->toBe('direct');
    });
});
