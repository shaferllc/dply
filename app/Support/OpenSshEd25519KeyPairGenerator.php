<?php

namespace App\Support;

use phpseclib3\Crypt\EC;

final class OpenSshEd25519KeyPairGenerator
{
    /**
     * @return array{0: string, 1: string} [private_openssh, public_openssh_one_line]
     *
     * @throws \RuntimeException When libsodium / Ed25519 generation is unavailable
     */
    public static function generate(): array
    {
        if (! function_exists('sodium_crypto_sign_keypair')) {
            throw new \RuntimeException(
                'SSH key generation requires the sodium PHP extension. Generate a key locally with `ssh-keygen -t ed25519 -C "you@example.com"` and paste the public key.'
            );
        }

        try {
            $private = EC::createKey('Ed25519');
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Could not generate an SSH key pair on this server. Generate one locally with `ssh-keygen -t ed25519 -C "you@example.com"` and paste the public key.',
                previous: $e
            );
        }

        $privateOpenssh = $private->toString('OpenSSH');
        $publicOpenssh = trim((string) $private->getPublicKey()->toString('OpenSSH'));

        return [$privateOpenssh, $publicOpenssh];
    }
}
