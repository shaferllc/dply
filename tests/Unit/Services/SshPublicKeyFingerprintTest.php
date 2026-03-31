<?php

namespace Tests\Unit\Services;

use App\Services\Servers\SshPublicKeyFingerprint;
use phpseclib3\Crypt\PublicKeyLoader;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SshPublicKeyFingerprintTest extends TestCase
{
    #[Test]
    public function it_returns_sha256_and_md5_for_derived_openssh_line(): void
    {
        $pem = file_get_contents(base_path('app/TaskRunner/Tests/fixtures/private_key.pem'));
        $key = PublicKeyLoader::loadPrivateKey($pem);
        $line = trim($key->getPublicKey()->toString('OpenSSH'));

        $fp = SshPublicKeyFingerprint::forLine($line);

        $this->assertNotNull($fp);
        $this->assertArrayHasKey('sha256', $fp);
        $this->assertArrayHasKey('md5', $fp);
        $this->assertStringStartsWith('SHA256:', $fp['sha256']);
        $this->assertStringStartsWith('MD5:', $fp['md5']);
    }

    #[Test]
    public function it_returns_null_for_garbage(): void
    {
        $this->assertNull(SshPublicKeyFingerprint::forLine('not-a-key'));
    }
}
