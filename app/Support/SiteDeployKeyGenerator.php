<?php

namespace App\Support;

use phpseclib3\Crypt\RSA;

class SiteDeployKeyGenerator
{
    /**
     * @return array{0: string, 1: string} [private_openssh, public_openssh]
     */
    public static function generate(): array
    {
        $private = RSA::createKey(4096);

        return [
            $private->toString('OpenSSH'),
            $private->getPublicKey()->toString('OpenSSH'),
        ];
    }
}
