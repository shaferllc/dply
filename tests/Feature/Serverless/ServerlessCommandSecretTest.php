<?php

declare(strict_types=1);

namespace Tests\Feature\Serverless\ServerlessCommandSecretTest;
use App\Models\Site;
uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('it mints and persists a command secret', function () {
    $site = Site::factory()->create();

    $secret = $site->ensureServerlessCommandSecret();

    $this->assertNotSame('', $secret);
    expect($site->fresh()->serverlessConfig()['command_secret'])->toBe($secret);
});
test('repeated calls return the same secret', function () {
    $site = Site::factory()->create();

    $first = $site->ensureServerlessCommandSecret();
    $second = $site->fresh()->ensureServerlessCommandSecret();

    expect($second)->toBe($first);
});
test('the command secret is independent of the webhook secret', function () {
    $site = Site::factory()->create();
    $commandSecret = $site->ensureServerlessCommandSecret();

    $this->assertNotSame($site->webhook_secret, $commandSecret);

    // Rotating the webhook secret leaves the command secret untouched —
    // the scheduler keeps working without a redeploy-secret mismatch.
    $site->update(['webhook_secret' => 'rotated-webhook-secret']);

    expect($site->fresh()->ensureServerlessCommandSecret())->toBe($commandSecret);
});
