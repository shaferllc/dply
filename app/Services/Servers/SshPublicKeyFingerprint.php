<?php

namespace App\Services\Servers;

use phpseclib3\Crypt\Common\PublicKey;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Exception\NoKeyLoadedException;

class SshPublicKeyFingerprint
{
    /**
     * @return array{sha256: string, md5: string}|null
     */
    public static function forLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }

        try {
            /** @var PublicKey $key */
            $key = PublicKeyLoader::loadPublicKey($line);
        } catch (NoKeyLoadedException|\Throwable) {
            return null;
        }

        $sha = $key->getFingerprint('sha256');
        $md5 = $key->getFingerprint('md5');
        if (! is_string($sha) || ! is_string($md5)) {
            return null;
        }

        return [
            'sha256' => 'SHA256:'.$sha,
            'md5' => 'MD5:'.$md5,
        ];
    }

    public static function shortSha256(string $line): ?string
    {
        $fp = self::forLine($line);

        return $fp['sha256'] ?? null;
    }
}
