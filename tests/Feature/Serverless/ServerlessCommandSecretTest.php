<?php

declare(strict_types=1);

namespace Tests\Feature\Serverless;

use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The serverless command secret signs dply's background ticks. It is
 * deliberately decoupled from the operator-rotatable webhook_secret so that
 * regenerating that secret can never silently break a function's scheduler.
 */
class ServerlessCommandSecretTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_mints_and_persists_a_command_secret(): void
    {
        $site = Site::factory()->create();

        $secret = $site->ensureServerlessCommandSecret();

        $this->assertNotSame('', $secret);
        $this->assertSame($secret, $site->fresh()->serverlessConfig()['command_secret']);
    }

    public function test_repeated_calls_return_the_same_secret(): void
    {
        $site = Site::factory()->create();

        $first = $site->ensureServerlessCommandSecret();
        $second = $site->fresh()->ensureServerlessCommandSecret();

        $this->assertSame($first, $second);
    }

    public function test_the_command_secret_is_independent_of_the_webhook_secret(): void
    {
        $site = Site::factory()->create();
        $commandSecret = $site->ensureServerlessCommandSecret();

        $this->assertNotSame($site->webhook_secret, $commandSecret);

        // Rotating the webhook secret leaves the command secret untouched —
        // the scheduler keeps working without a redeploy-secret mismatch.
        $site->update(['webhook_secret' => 'rotated-webhook-secret']);

        $this->assertSame($commandSecret, $site->fresh()->ensureServerlessCommandSecret());
    }
}
