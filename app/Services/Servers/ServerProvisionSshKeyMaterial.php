<?php

namespace App\Services\Servers;

use phpseclib3\Crypt\RSA;

class ServerProvisionSshKeyMaterial
{
    /**
     * @return array{
     *     recovery_private_key: string,
     *     recovery_public_key: string,
     *     operational_private_key: string,
     *     operational_public_key: string
     * }
     */
    public function generate(): array
    {
        $recovery = RSA::createKey(2048);
        $operational = RSA::createKey(2048);

        return [
            'recovery_private_key' => $recovery->toString('OpenSSH'),
            'recovery_public_key' => $recovery->getPublicKey()->toString('OpenSSH'),
            'operational_private_key' => $operational->toString('OpenSSH'),
            'operational_public_key' => $operational->getPublicKey()->toString('OpenSSH'),
        ];
    }
}
