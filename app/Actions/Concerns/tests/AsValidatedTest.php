<?php

declare(strict_types=1);

use App\Actions\Concerns\AsValidated;

describe('AsValidated', function () {
    it('trait can be used by a class', function () {
        $instance = new class
        {
            use AsValidated;
        };

        expect($instance)->toBeInstanceOf(get_class($instance));
    });
});
