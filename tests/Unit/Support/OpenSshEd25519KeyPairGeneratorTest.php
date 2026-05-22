<?php

namespace Tests\Unit\Support\OpenSshEd25519KeyPairGeneratorTest;

use App\Support\OpenSshEd25519KeyPairGenerator;

test('generate returns openssh ed25519 pair', function () {
    if (! function_exists('sodium_crypto_sign_keypair')) {
        $this->markTestSkipped('sodium extension required for Ed25519 generation.');
    }

    [$private, $public] = OpenSshEd25519KeyPairGenerator::generate();

    $this->assertStringContainsString('BEGIN OPENSSH PRIVATE KEY', $private);
    $this->assertStringContainsString('END OPENSSH PRIVATE KEY', $private);
    expect($public)->toMatch('/^ssh-ed25519\s+\S+/m');
});
