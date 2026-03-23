<?php

declare(strict_types=1);

namespace Dply\Core\Tests;

use Dply\Core\Security\WebhookSignature;
use PHPUnit\Framework\TestCase;

final class WebhookSignatureTest extends TestCase
{
    public function test_legacy_header_verifies(): void
    {
        $secret = 'test-secret';
        $body = '{"ref":"refs/heads/main"}';
        $expected = WebhookSignature::expectedLegacyHeader($secret, $body);

        $this->assertSame('legacy', WebhookSignature::verify($secret, $body, $expected, null));
    }

    public function test_timestamped_header_verifies(): void
    {
        $secret = 'test-secret';
        $body = '{"ref":"refs/heads/main"}';
        $ts = 1_700_000_000;
        $expected = WebhookSignature::expectedTimestampedHeader($secret, $ts, $body);

        $this->assertSame('timestamped', WebhookSignature::verify($secret, $body, $expected, $ts));
    }

    public function test_wrong_secret_fails(): void
    {
        $body = '{}';
        $header = WebhookSignature::expectedLegacyHeader('right', $body);

        $this->assertNull(WebhookSignature::verify('wrong', $body, $header, null));
    }
}
