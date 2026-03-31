<?php

declare(strict_types=1);

use App\Actions\Concerns\AsCompensatable;

describe('AsCompensatable', function () {
    it('trait can be used by a class', function () {
        $instance = new class
        {
            use AsCompensatable;
        };

        expect($instance)->toBeInstanceOf(get_class($instance));
    });
});
