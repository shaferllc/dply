<?php

declare(strict_types=1);

use App\Actions\Concerns\AsMiddleware;

describe('AsMiddleware', function () {
    it('trait can be used by a class', function () {
        $instance = new class
        {
            use AsMiddleware;
        };

        expect($instance)->toBeInstanceOf(get_class($instance));
    });
});
