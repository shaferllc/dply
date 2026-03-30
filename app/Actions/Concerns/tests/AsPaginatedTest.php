<?php

declare(strict_types=1);

use App\Actions\Concerns\AsPaginated;

describe('AsPaginated', function () {
    it('trait can be used by a class', function () {
        $instance = new class
        {
            use AsPaginated;
        };

        expect($instance)->toBeInstanceOf(get_class($instance));
    });
});
