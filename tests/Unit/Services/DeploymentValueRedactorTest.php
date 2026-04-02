<?php

namespace Tests\Unit\Services;

use App\Services\Deploy\DeploymentValueRedactor;
use Tests\TestCase;

class DeploymentValueRedactorTest extends TestCase
{
    public function test_it_redacts_sensitive_context_values(): void
    {
        $redactor = app(DeploymentValueRedactor::class);

        $redacted = $redactor->redactContext([
            'token' => 'super-secret-token-value',
            'normal' => 'visible',
            'nested' => [
                'private_key' => '-----BEGIN PRIVATE KEY-----abc',
            ],
        ]);

        $this->assertSame('[REDACTED]', $redacted['token']);
        $this->assertSame('visible', $redacted['normal']);
        $this->assertSame('[REDACTED]', $redacted['nested']['private_key']);
    }
}
