<?php

declare(strict_types=1);

use App\Actions\Concerns\AsResource;

describe('AsResource', function () {
    it('trait can be used by a class', function () {
        $instance = new class
        {
            use AsResource;
        };

        expect($instance)->toBeInstanceOf(get_class($instance));
    });
});
