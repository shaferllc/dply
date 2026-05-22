<?php

namespace Tests\Unit\Services\SshPublicKeyFingerprintTest;

use App\Services\Servers\SshPublicKeyFingerprint;
use phpseclib3\Crypt\PublicKeyLoader;

it('returns sha256 and md5 for derived openssh line', function () {
    $pem = file_get_contents(base_path('app/TaskRunner/Tests/fixtures/private_key.pem'));
    $key = PublicKeyLoader::loadPrivateKey($pem);
    $line = trim($key->getPublicKey()->toString('OpenSSH'));

    $fp = SshPublicKeyFingerprint::forLine($line);

    expect($fp)->not->toBeNull();
    expect($fp)->toHaveKey('sha256');
    expect($fp)->toHaveKey('md5');
    expect($fp['sha256'])->toStartWith('SHA256:');
    expect($fp['md5'])->toStartWith('MD5:');
});

it('returns null for garbage', function () {
    expect(SshPublicKeyFingerprint::forLine('not-a-key'))->toBeNull();
});
