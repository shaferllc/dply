<?php

declare(strict_types=1);

use App\Actions\Concerns\AsRateLimiter;

describe('AsRateLimiter', function () {
    it('trait can be used by a class', function () {
        $instance = new class
        {
            use AsRateLimiter;
        };

        expect($instance)->toBeInstanceOf(get_class($instance));
    });
});
