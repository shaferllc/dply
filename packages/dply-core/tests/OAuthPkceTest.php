<?php

declare(strict_types=1);

namespace Dply\Core\Tests;

use Dply\Core\Auth\OAuthPkce;
use PHPUnit\Framework\TestCase;

class OAuthPkceTest extends TestCase
{
    public function test_verifier_length_and_challenge_consistency(): void
    {
        $v = OAuthPkce::generateCodeVerifier(64);
        $this->assertGreaterThanOrEqual(43, strlen($v));

        $c = OAuthPkce::codeChallengeS256($v);
        $this->assertSame($c, OAuthPkce::codeChallengeS256($v));
        $this->assertGreaterThan(20, strlen($c));
    }
}
