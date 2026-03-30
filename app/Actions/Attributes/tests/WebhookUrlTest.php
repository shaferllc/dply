<?php

declare(strict_types=1);

use App\Actions\Attributes\WebhookUrl;

describe('WebhookUrl', function () {
    it('can be instantiated and stores url', function () {
        $attr = new WebhookUrl('https://example.com/webhook');

        expect($attr)->toBeInstanceOf(WebhookUrl::class);
        expect($attr->url)->toBe('https://example.com/webhook');
    });
});
