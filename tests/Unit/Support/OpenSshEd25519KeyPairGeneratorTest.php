<?php

namespace Tests\Unit\Support;

use App\Support\OpenSshEd25519KeyPairGenerator;
use PHPUnit\Framework\TestCase;

class OpenSshEd25519KeyPairGeneratorTest extends TestCase
{
    public function test_generate_returns_openssh_ed25519_pair(): void
    {
        if (! function_exists('sodium_crypto_sign_keypair')) {
            $this->markTestSkipped('sodium extension required for Ed25519 generation.');
        }

        [$private, $public] = OpenSshEd25519KeyPairGenerator::generate();

        $this->assertStringContainsString('BEGIN OPENSSH PRIVATE KEY', $private);
        $this->assertStringContainsString('END OPENSSH PRIVATE KEY', $private);
        $this->assertMatchesRegularExpression('/^ssh-ed25519\s+\S+/m', $public);
    }
}
