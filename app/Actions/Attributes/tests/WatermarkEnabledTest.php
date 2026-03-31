<?php

declare(strict_types=1);

use App\Actions\Attributes\WatermarkEnabled;

describe('WatermarkEnabled', function () {
    it('can be instantiated with default', function () {
        $attr = new WatermarkEnabled;

        expect($attr)->toBeInstanceOf(WatermarkEnabled::class);
        expect($attr->enabled)->toBeTrue();
    });

    it('can be instantiated with custom value', function () {
        $attr = new WatermarkEnabled(false);

        expect($attr->enabled)->toBeFalse();
    });
});
