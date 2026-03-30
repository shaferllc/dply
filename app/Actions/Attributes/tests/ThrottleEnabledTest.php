<?php

declare(strict_types=1);

use App\Actions\Attributes\ThrottleEnabled;

describe('ThrottleEnabled', function () {
    it('can be instantiated with default', function () {
        $attr = new ThrottleEnabled;

        expect($attr)->toBeInstanceOf(ThrottleEnabled::class);
        expect($attr->enabled)->toBeTrue();
    });

    it('can be instantiated with custom value', function () {
        $attr = new ThrottleEnabled(false);

        expect($attr->enabled)->toBeFalse();
    });
});
