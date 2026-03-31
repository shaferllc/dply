<?php

declare(strict_types=1);

use App\Actions\Attributes\Transformations;

describe('Transformations', function () {
    it('can be instantiated and stores transformations', function () {
        $transformations = [
            'id' => 'user_id',
            'email' => 'email_address',
        ];
        $attr = new Transformations($transformations);

        expect($attr)->toBeInstanceOf(Transformations::class);
        expect($attr->transformations)->toBe($transformations);
    });
});
