<?php

declare(strict_types=1);

use App\Actions\Attributes\TimeoutEnabled;

describe('TimeoutEnabled', function () {
    it('can be instantiated with default', function () {
        $attr = new TimeoutEnabled;

        expect($attr)->toBeInstanceOf(TimeoutEnabled::class);
        expect($attr->enabled)->toBeTrue();
    });

    it('can be instantiated with custom value', function () {
        $attr = new TimeoutEnabled(false);

        expect($attr->enabled)->toBeFalse();
    });
});
