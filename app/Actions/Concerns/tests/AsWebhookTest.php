<?php

declare(strict_types=1);

use App\Actions\Concerns\AsWebhook;

describe('AsWebhook', function () {
    it('trait can be used by a class', function () {
        $instance = new class
        {
            use AsWebhook;
        };

        expect($instance)->toBeInstanceOf(get_class($instance));
    });
});
