<?php

declare(strict_types=1);

namespace App\Support\Ssh;

use phpseclib3\Crypt\EC;

final class EphemeralSshKeyGenerator
{
    /**
     * @return array{0: string, 1: string, 2: string} private OpenSSH, public OpenSSH, sha256 fingerprint
     */
    public function generate(string $comment): array
    {
        $key = EC::createKey('Ed25519');
        $privateOpenSsh = (string) $key->toString('OpenSSH');
        $publicOpenSsh = (string) $key->getPublicKey()->toString('OpenSSH', [
            'comment' => $comment,
        ]);
        $fingerprint = (string) $key->getPublicKey()->getFingerprint('sha256');

        return [$privateOpenSsh, $publicOpenSsh, $fingerprint];
    }
}
