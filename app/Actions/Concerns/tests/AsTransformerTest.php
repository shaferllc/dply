<?php

declare(strict_types=1);

use App\Actions\Concerns\AsTransformer;

describe('AsTransformer', function () {
    it('trait can be used by a class', function () {
        $instance = new class
        {
            use AsTransformer;
        };

        expect($instance)->toBeInstanceOf(get_class($instance));
    });
});
