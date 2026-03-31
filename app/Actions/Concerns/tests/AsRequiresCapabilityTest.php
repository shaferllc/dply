<?php

declare(strict_types=1);

use App\Actions\Concerns\AsRequiresCapability;

describe('AsRequiresCapability', function () {
    it('trait can be used by a class', function () {
        $instance = new class
        {
            use AsRequiresCapability;
        };

        expect($instance)->toBeInstanceOf(get_class($instance));
    });
});
