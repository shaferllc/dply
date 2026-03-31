<?php

declare(strict_types=1);

use App\Actions\Attributes\VersionHeader;

describe('VersionHeader', function () {
    it('can be instantiated with default', function () {
        $attr = new VersionHeader;

        expect($attr)->toBeInstanceOf(VersionHeader::class);
        expect($attr->header)->toBe('API-Version');
    });

    it('can be instantiated with custom header', function () {
        $attr = new VersionHeader('X-Custom-Version');

        expect($attr->header)->toBe('X-Custom-Version');
    });
});
