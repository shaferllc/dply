<?php

declare(strict_types=1);

use App\Actions\Concerns\AsConditional;

describe('AsConditional', function () {
    it('trait can be used by a class', function () {
        $instance = new class
        {
            use AsConditional;
        };

        expect($instance)->toBeInstanceOf(get_class($instance));
    });
});
