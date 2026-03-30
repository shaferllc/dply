<?php

declare(strict_types=1);

use App\Actions\Attributes\WebhookMethod;

describe('WebhookMethod', function () {
    it('can be instantiated and stores method', function () {
        $attr = new WebhookMethod('post');

        expect($attr)->toBeInstanceOf(WebhookMethod::class);
        expect($attr->method)->toBe('post');
    });
});
