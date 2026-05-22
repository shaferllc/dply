<?php

namespace Tests\Unit\Services\DeploymentValueRedactorTest;

use App\Services\Deploy\DeploymentValueRedactor;

test('it redacts sensitive context values', function () {
    $redactor = app(DeploymentValueRedactor::class);

    $redacted = $redactor->redactContext([
        'token' => 'super-secret-token-value',
        'normal' => 'visible',
        'nested' => [
            'private_key' => '-----BEGIN PRIVATE KEY-----abc',
        ],
    ]);

    expect($redacted['token'])->toBe('[REDACTED]');
    expect($redacted['normal'])->toBe('visible');
    expect($redacted['nested']['private_key'])->toBe('[REDACTED]');
});
