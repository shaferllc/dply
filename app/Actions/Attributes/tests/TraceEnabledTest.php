<?php

declare(strict_types=1);

use App\Actions\Attributes\TraceEnabled;

describe('TraceEnabled', function () {
    it('can be instantiated with default', function () {
        $attr = new TraceEnabled;

        expect($attr)->toBeInstanceOf(TraceEnabled::class);
        expect($attr->enabled)->toBeTrue();
    });

    it('can be instantiated with custom value', function () {
        $attr = new TraceEnabled(false);

        expect($attr->enabled)->toBeFalse();
    });
});
